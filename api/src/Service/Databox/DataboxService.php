<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Connected data boxes: adding / removing, the logins (encrypted at rest), connection tests and the rule which
 * login may be used for what.
 *
 * Two login purposes per box:
 *  - 'sync'   unattended background synchronisation. Should be an ISDS user with ONLY the "Zobrazovat seznamy,
 *             historii a doručenky" right: such a user lists envelopes but cannot read, so the sync never delivers
 *             a message (see {@see DataboxPrivileges}). A login that can read is refused for the sync unless the
 *             owner explicitly opted in per box.
 *  - 'action' optional, used only for explicit user actions: opening a message (needs the read right, may deliver),
 *             sending (needs the send right), searching boxes.
 */
final class DataboxService
{
    public const PURPOSES = ['sync', 'action'];

    public function __construct(
        private readonly DataboxRepository $boxes,
        private readonly SecretEncryption $crypto,
        private readonly IsdsClient $isds,
        private readonly LoggerInterface $logger,
    ) {}

    // ---- presentation (no secrets ever) ----

    /** @return list<array<string,mixed>> */
    public function listPublic(int $supplierId): array
    {
        return array_map($this->present(...), $this->boxes->listForSupplier($supplierId));
    }

    /** @param array<string,mixed> $box @return array<string,mixed> public view of a box and its logins */
    public function present(array $box): array
    {
        $credentials = [];
        foreach ($box['credentials'] as $purpose => $c) {
            $privileges = $this->privilegesOf($c);
            $credentials[$purpose] = [
                'purpose' => $purpose,
                'username' => $c['username'],
                'user_type' => $c['user_type'],
                'privileges' => $privileges?->mask,
                'privilege_labels' => $privileges?->labels() ?? [],
                'can_list' => $privileges?->canList(),
                'can_read' => $privileges?->canReadReceived(),
                'can_read_personal' => $privileges?->canReadPersonal(),
                'can_send' => $privileges?->canSend(),
                'can_search' => $privileges?->canSearch(),
                'password_expires_at' => $c['password_expires_at'],
                'last_check_at' => $c['last_check_at'],
                'last_check_status' => $c['last_check_status'],
                'last_check_message' => $c['last_check_message'],
            ];
        }
        return [
            'id' => $box['id'],
            'label' => $box['label'],
            'environment' => $box['environment'],
            'db_id' => $box['db_id'],
            'db_type' => $box['db_type'],
            'owner_name' => $box['owner_name'],
            'is_active' => $box['is_active'],
            'allow_delivering_sync' => $box['allow_delivering_sync'],
            'last_sync_at' => $box['last_sync_at'],
            'last_sync_status' => $box['last_sync_status'],
            'last_sync_message' => $box['last_sync_message'],
            'sync_paused_until' => $box['sync_paused_until'],
            'sync' => $this->syncState($box),
            'credentials' => $credentials,
        ];
    }

    /**
     * Whether the background sync of this box may run, and why not.
     *
     * @param array<string,mixed> $box
     * @return array{state:string, delivers:bool}
     *   state: ok | opt_in (reads and delivers, owner agreed) | blocked_delivers | blocked_no_list | unverified | no_credential | login_failed
     */
    public function syncState(array $box): array
    {
        $sync = $box['credentials']['sync'] ?? null;
        if ($sync === null) {
            return ['state' => 'no_credential', 'delivers' => false];
        }
        if (in_array($sync['last_check_status'], ['bad_credentials', 'locked'], true)) {
            return ['state' => 'login_failed', 'delivers' => false];
        }
        $privileges = $this->privilegesOf($sync);
        if ($privileges === null) {
            return ['state' => 'unverified', 'delivers' => false];
        }
        return match ($privileges->syncSafety()) {
            'safe' => ['state' => 'ok', 'delivers' => false],
            'delivers' => $box['allow_delivering_sync']
                ? ['state' => 'opt_in', 'delivers' => true]
                : ['state' => 'blocked_delivers', 'delivers' => true],
            default => ['state' => 'blocked_no_list', 'delivers' => false],
        };
    }

    // ---- lifecycle ----

    /**
     * Connect a box. The box ID, type and name come from the login itself (GetOwnerInfoFromLogin2), never from the user.
     *
     * @param array{label?:string, environment?:string, sync:array{username:string, password:string},
     *              action?:?array{username:string, password:string}, allow_delivering_sync?:bool} $input
     * @return array<string,mixed> public view
     * @throws DataboxException|IsdsException
     */
    public function add(int $supplierId, array $input): array
    {
        $environment = (string) ($input['environment'] ?? IsdsEnvironment::PRODUCTION);
        if (!IsdsEnvironment::isValid($environment)) {
            throw new DataboxException('validation_failed', 'Neplatné prostředí datových schránek.', 400);
        }
        $logins = ['sync' => $this->requireLogin($input['sync'] ?? null, 'Přihlášení pro synchronizaci')];
        if (!empty($input['action']['username']) || !empty($input['action']['password'])) {
            $logins['action'] = $this->requireLogin($input['action'], 'Přihlášení pro akce');
        }

        $probes = [];
        $owner = null;
        foreach ($logins as $purpose => $login) {
            $probes[$purpose] = $this->probe(new IsdsAccess($environment, $login));
            $thisOwner = $probes[$purpose]['owner'];
            if ($owner !== null && $owner['db_id'] !== $thisOwner['db_id']) {
                throw new DataboxException('box_mismatch', 'Obě přihlášení musí patřit do stejné datové schránky.');
            }
            $owner = $thisOwner;
        }
        $dbId = DataboxId::normalize((string) $owner['db_id']);
        if (!DataboxId::isValid($dbId)) {
            throw new IsdsException('invalid_response', 'Datová schránka vrátila neplatné ID schránky.');
        }
        if ($this->boxes->findByBoxId($supplierId, $environment, $dbId) !== null) {
            throw new DataboxException('duplicate', 'Tato datová schránka už je připojená.', 409);
        }

        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            $label = (string) ($owner['name'] ?? $dbId);
        }
        $boxId = $this->boxes->insert(
            $supplierId,
            mb_substr($label, 0, 100),
            $environment,
            $dbId,
            mb_substr((string) ($owner['db_type'] ?? ''), 0, 20),
            $owner['name'] !== null ? mb_substr((string) $owner['name'], 0, 255) : null,
            (bool) ($input['allow_delivering_sync'] ?? false),
        );
        foreach ($logins as $purpose => $login) {
            $this->boxes->saveCredential($boxId, $purpose, $login->username, $this->crypto->encrypt($login->password()));
            $this->recordOk($boxId, $purpose, $probes[$purpose]);
        }
        $this->logger->info('Data box connected', ['box_id' => $boxId, 'supplier_id' => $supplierId, 'db_id' => $dbId, 'environment' => $environment]);

        return $this->present($this->boxes->find($boxId));
    }

    /**
     * "Ověřit připojení": log in with every stored login (read-only calls), refresh privileges and password expiry.
     *
     * @return array<string,mixed> public view, plus `check` with the per-login outcome
     */
    public function verify(int $supplierId, int $boxId): array
    {
        $box = $this->requireBox($supplierId, $boxId);
        $results = [];
        foreach ($box['credentials'] as $purpose => $cred) {
            try {
                $probe = $this->probe($this->accessFor($box, $purpose));
                $this->assertSameBox($box, $probe);
                $this->recordOk($boxId, $purpose, $probe);
                $results[$purpose] = ['ok' => true, 'message' => null];
            } catch (IsdsException $e) {
                $this->recordFailure($boxId, $purpose, $e);
                $results[$purpose] = ['ok' => false, 'reason' => $e->reason, 'message' => $e->getMessage()];
            } catch (DataboxException $e) {
                $this->boxes->recordCheck($boxId, $purpose, 'error', $e->getMessage());
                $results[$purpose] = ['ok' => false, 'reason' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }
        // A successful test of the sync login lifts the pause that a previous failure put on the cron.
        if (($results['sync']['ok'] ?? false) === true) {
            $this->clearSyncStatus($boxId);
        }
        $out = $this->present($this->boxes->find($boxId));
        $out['check'] = $results;
        return $out;
    }

    /**
     * Replace (or add) one login of a box after proving it belongs to the same box.
     *
     * @return array<string,mixed> public view
     */
    public function setCredential(int $supplierId, int $boxId, string $purpose, string $username, #[\SensitiveParameter] string $password): array
    {
        $this->assertPurpose($purpose);
        $box = $this->requireBox($supplierId, $boxId);
        $login = $this->requireLogin(['username' => $username, 'password' => $password], 'Přihlášení');
        $probe = $this->probe(new IsdsAccess($box['environment'], $login));
        $this->assertSameBox($box, $probe);
        $this->boxes->saveCredential($boxId, $purpose, $login->username, $this->crypto->encrypt($login->password()));
        $this->recordOk($boxId, $purpose, $probe);
        if ($purpose === 'sync') {
            $this->clearSyncStatus($boxId);
        }
        return $this->present($this->boxes->find($boxId));
    }

    /** @return array<string,mixed> public view */
    public function removeCredential(int $supplierId, int $boxId, string $purpose): array
    {
        $this->assertPurpose($purpose);
        $this->requireBox($supplierId, $boxId);
        $this->boxes->deleteCredential($boxId, $purpose);
        return $this->present($this->boxes->find($boxId));
    }

    /**
     * @param array{label?:string, is_active?:bool, allow_delivering_sync?:bool} $fields
     * @return array<string,mixed> public view
     */
    public function update(int $supplierId, int $boxId, array $fields): array
    {
        $this->requireBox($supplierId, $boxId);
        $update = [];
        if (isset($fields['label'])) {
            $label = trim((string) $fields['label']);
            if ($label === '' || mb_strlen($label) > 100) {
                throw new DataboxException('validation_failed', 'Název schránky musí mít 1 až 100 znaků.', 400);
            }
            $update['label'] = $label;
        }
        if (isset($fields['is_active'])) {
            $update['is_active'] = (bool) $fields['is_active'];
        }
        if (isset($fields['allow_delivering_sync'])) {
            $update['allow_delivering_sync'] = (bool) $fields['allow_delivering_sync'];
            // The stored "sync refused" status describes the old setting; the next run writes the real one.
            $update += ['last_sync_status' => null, 'last_sync_message' => null];
        }
        $this->boxes->update($boxId, $update);
        return $this->present($this->boxes->find($boxId));
    }

    public function remove(int $supplierId, int $boxId, bool $confirmMessagesDeleted): void
    {
        $this->requireBox($supplierId, $boxId);
        $count = $this->boxes->messageCount($boxId);
        if ($count > 0 && !$confirmMessagesDeleted) {
            throw new DataboxException(
                'confirm_required',
                "Odebráním schránky se smažou i uložené zprávy a doručenky ({$count}). Potvrďte smazání, nebo schránku jen vypněte.",
                409,
                ['message_count' => $count],
            );
        }
        $this->boxes->delete($boxId);
        $this->logger->info('Data box removed', ['box_id' => $boxId, 'supplier_id' => $supplierId, 'messages' => $count]);
    }

    // ---- logins for the other services ----

    /** Decrypt one login of the box. @param array<string,mixed> $box */
    public function accessFor(array $box, string $purpose): IsdsAccess
    {
        $cred = $box['credentials'][$purpose] ?? null;
        if ($cred === null) {
            throw new DataboxException('no_login', 'Pro tuto datovou schránku není uloženo požadované přihlášení.');
        }
        try {
            $password = $this->crypto->decrypt((string) $cred['password_enc']);
        } catch (\RuntimeException) {
            throw new DataboxException('credential_unreadable', 'Uložené heslo se nepodařilo dešifrovat (změnil se šifrovací klíč aplikace). Zadejte přihlášení znovu.', 500);
        }
        return new IsdsAccess($box['environment'], new IsdsCredentials((string) $cred['username'], $password));
    }

    /**
     * The login to use for an explicit user action, chosen by the right it needs. The 'action' login is preferred,
     * the sync login is the fallback. Unknown privileges are fetched first.
     *
     * @param array<string,mixed> $box
     * @param callable(DataboxPrivileges):bool $needs
     * @throws DataboxException when no stored login has the right ("missingRight" is the Czech name of the right)
     */
    public function accessForRight(array $box, callable $needs, string $missingRight): IsdsAccess
    {
        foreach (['action', 'sync'] as $purpose) {
            if (!isset($box['credentials'][$purpose])) {
                continue;
            }
            $privileges = $this->privilegesOf($box['credentials'][$purpose]) ?? $this->refreshPrivileges($box, $purpose);
            if ($privileges !== null && $needs($privileges)) {
                return $this->accessFor($box, $purpose);
            }
        }
        throw new DataboxException(
            'missing_right',
            "Žádné z uložených přihlášení k této schránce nemá právo „{$missingRight}“. V nastavení datových schránek přidejte přihlášení s tímto právem.",
            422,
            ['missing_right' => $missingRight],
        );
    }

    /** Fetch and persist the privileges of one stored login. @param array<string,mixed> $box */
    public function refreshPrivileges(array $box, string $purpose): ?DataboxPrivileges
    {
        $probe = $this->probe($this->accessFor($box, $purpose));
        $this->recordOk((int) $box['id'], $purpose, $probe);
        return $probe['privileges'];
    }

    /** @param array<string,mixed> $cred a databox_credentials row */
    public function privilegesOf(array $cred): ?DataboxPrivileges
    {
        if ($cred['user_privils'] === null && $cred['user_type'] === null) {
            return null;
        }
        return DataboxPrivileges::fromUserInfo($cred['user_type'] ?? null, $cred['user_privils'] !== null ? (int) $cred['user_privils'] : null);
    }

    // ---- internals ----

    /**
     * Log in and learn who we are: the three read-only calls that need no privilege.
     *
     * @return array{owner:array<string,mixed>, user:array<string,mixed>, privileges:DataboxPrivileges, password_expires_at:?\DateTimeImmutable}
     */
    public function probe(IsdsAccess $access): array
    {
        $owner = $this->isds->ownerInfo($access);
        if ($owner['db_id'] === null) {
            throw IsdsException::invalidResponse('chybí ID schránky');
        }
        $user = $this->isds->userInfo($access);
        $expires = null;
        try {
            $expires = $this->isds->passwordExpiry($access);
        } catch (IsdsException $e) {
            if ($e->isAuthFailure()) {
                throw $e;
            }
            // an informational field; its failure must not block the connection test
        }
        return [
            'owner' => $owner,
            'user' => $user,
            'privileges' => DataboxPrivileges::fromUserInfo($user['user_type'], $user['privileges']),
            'password_expires_at' => $expires,
        ];
    }

    /** @param array<string,mixed> $box @param array{owner:array<string,mixed>} $probe @throws DataboxException */
    private function assertSameBox(array $box, array $probe): void
    {
        if (DataboxId::normalize((string) $probe['owner']['db_id']) !== $box['db_id']) {
            throw new DataboxException('box_mismatch', 'Přihlášení patří do jiné datové schránky, než je uložená.');
        }
    }

    /** A successful login test lifts the pause (and the last error) that a failed sync put on the box. */
    private function clearSyncStatus(int $boxId): void
    {
        $this->boxes->update($boxId, ['sync_paused_until' => null, 'last_sync_status' => null, 'last_sync_message' => null]);
    }

    /** @param array{owner:array<string,mixed>, user:array<string,mixed>, privileges:DataboxPrivileges, password_expires_at:?\DateTimeImmutable} $probe */
    private function recordOk(int $boxId, string $purpose, array $probe): void
    {
        $this->boxes->recordCheck($boxId, $purpose, 'ok', null, [
            'user_type' => $probe['user']['user_type'],
            'user_privils' => $probe['user']['privileges'],
            'password_expires_at' => $probe['password_expires_at']?->format('Y-m-d H:i:s'),
        ]);
    }

    private function recordFailure(int $boxId, string $purpose, IsdsException $e): void
    {
        $status = match ($e->reason) {
            'bad_credentials' => 'bad_credentials',
            'account_locked', 'ip_blocked' => 'locked',
            default => 'error',
        };
        $this->boxes->recordCheck($boxId, $purpose, $status, $e->getMessage());
    }

    /** @param mixed $login @throws DataboxException */
    private function requireLogin(mixed $login, string $what): IsdsCredentials
    {
        $username = trim((string) ($login['username'] ?? ''));
        $password = (string) ($login['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new DataboxException('validation_failed', "{$what}: vyplňte uživatelské jméno i heslo.", 400);
        }
        if (strlen($username) > 64 || strlen($password) > 128) {
            throw new DataboxException('validation_failed', "{$what}: jméno nebo heslo je příliš dlouhé.", 400);
        }
        return new IsdsCredentials($username, $password);
    }

    /** @return array<string,mixed> the box of this supplier with its logins @throws DataboxException */
    public function requireBox(int $supplierId, int $boxId): array
    {
        $box = $this->boxes->find($boxId, $supplierId);
        if ($box === null) {
            throw new DataboxException('not_found', 'Datová schránka nenalezena.', 404);
        }
        return $box;
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new DataboxException('validation_failed', 'Neplatný účel přihlášení.', 400);
        }
    }
}
