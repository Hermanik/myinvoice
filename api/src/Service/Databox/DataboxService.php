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
    public const MODE_ISDS = 'isds';
    public const MODE_EMAIL = 'email';
    /** Box types a holder can pick for a box that is watched by e-mail only (nothing tells them apart without a login). */
    public const EMAIL_BOX_TYPES = ['FO', 'PFO', 'PO'];

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

    /**
     * The boxes the owner opened to the accountant role, in the reduced view {@see presentForAccountant()}.
     *
     * @return list<array<string,mixed>>
     */
    public function listForAccountant(int $supplierId): array
    {
        return array_map($this->presentForAccountant(...), $this->boxes->listAccountantVisible($supplierId));
    }

    /**
     * What the accountant may know about a box: what it is, never how the application logs in to it (no logins,
     * rights, sync state or error texts).
     *
     * @param array<string,mixed> $box
     * @return array<string,mixed>
     */
    public function presentForAccountant(array $box): array
    {
        return [
            'id' => $box['id'],
            'label' => $box['label'],
            'environment' => $box['environment'],
            'connection_mode' => $box['connection_mode'] ?? self::MODE_ISDS,
            'db_id' => $box['db_id'],
            'db_type' => $box['db_type'],
            'owner_name' => $box['owner_name'],
            'is_active' => $box['is_active'],
            'accountant_visible' => true,
        ];
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
            'connection_mode' => $box['connection_mode'] ?? self::MODE_ISDS,
            'db_id' => $box['db_id'],
            'db_type' => $box['db_type'],
            'owner_name' => $box['owner_name'],
            'is_active' => $box['is_active'],
            'allow_delivering_sync' => $box['allow_delivering_sync'],
            'accountant_visible' => $box['accountant_visible'],
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
     *   state: ok | opt_in (reads and delivers, owner agreed) | blocked_delivers | blocked_no_list | unverified | no_credential | login_failed | email_only
     */
    public function syncState(array $box): array
    {
        if (self::isEmailOnly($box)) {
            return ['state' => 'email_only', 'delivers' => false];
        }
        $sync = $box['credentials']['sync'] ?? null;
        if ($sync === null) {
            return ['state' => 'no_credential', 'delivers' => false];
        }
        if ($this->isFlagged($sync)) {
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
     * With `mode` = 'email' no login is involved: the box is only watched through its notification e-mails, so the holder
     * names it himself ({@see addEmailOnly()}).
     *
     * @param array{mode?:string, label?:string, environment?:string, sync?:array{username:string, password:string},
     *              action?:?array{username:string, password:string}, allow_delivering_sync?:bool,
     *              db_id?:string, db_type?:string, owner_name?:?string} $input
     * @return array<string,mixed> public view
     * @throws DataboxException|IsdsException
     */
    public function add(int $supplierId, #[\SensitiveParameter] array $input): array
    {
        if (($input['mode'] ?? self::MODE_ISDS) === self::MODE_EMAIL) {
            return $this->addEmailOnly($supplierId, $input);
        }
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
     * A box watched through ISDS notification e-mails only: nothing is asked of ISDS and no password is stored. The holder
     * names the box, because only a login could tell its type and owner. Notices exist for the production system only.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> public view
     * @throws DataboxException
     */
    private function addEmailOnly(int $supplierId, array $input): array
    {
        if (($input['environment'] ?? IsdsEnvironment::PRODUCTION) !== IsdsEnvironment::PRODUCTION) {
            throw new DataboxException('validation_failed', 'Upozornění e-mailem posílá jen ostrý systém datových schránek.', 400);
        }
        $dbId = DataboxId::normalize((string) ($input['db_id'] ?? ''));
        if (!DataboxId::isValid($dbId)) {
            throw new DataboxException('validation_failed', 'ID datové schránky není platné (7 znaků s kontrolním znakem).', 400);
        }
        $dbType = strtoupper(trim((string) ($input['db_type'] ?? '')));
        if (!in_array($dbType, self::EMAIL_BOX_TYPES, true)) {
            throw new DataboxException('validation_failed', 'Zvolte typ schránky (FO, PFO nebo PO).', 400);
        }
        if ($this->boxes->findByBoxId($supplierId, IsdsEnvironment::PRODUCTION, $dbId) !== null) {
            throw new DataboxException('duplicate', 'Tato datová schránka už je připojená.', 409);
        }
        $ownerName = trim((string) ($input['owner_name'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        if ($label === '') {
            $label = $ownerName !== '' ? $ownerName : $dbId;
        }
        $boxId = $this->boxes->insert(
            $supplierId,
            mb_substr($label, 0, 100),
            IsdsEnvironment::PRODUCTION,
            $dbId,
            $dbType,
            $ownerName !== '' ? mb_substr($ownerName, 0, 255) : null,
            false,
            self::MODE_EMAIL,
        );
        $this->logger->info('Data box connected by e-mail notices only', ['box_id' => $boxId, 'supplier_id' => $supplierId, 'db_id' => $dbId]);
        return $this->present($this->boxes->find($boxId));
    }

    /** True for a box that is watched through notification e-mails only: it has no login and must never be sent to ISDS. @param array<string,mixed> $box */
    public static function isEmailOnly(array $box): bool
    {
        return ($box['connection_mode'] ?? self::MODE_ISDS) === self::MODE_EMAIL;
    }

    /** Every ISDS action starts here: a box watched by e-mail has nothing to log in with. @param array<string,mixed> $box @throws DataboxException */
    public function requireIsdsMode(array $box): void
    {
        if (self::isEmailOnly($box)) {
            throw new DataboxException(
                'email_only',
                'Tato schránka je sledovaná jen přes e-mailová upozornění, aplikace se do ní nepřihlašuje. Zprávu otevřete na portálu datových schránek (www.datovka.gov.cz).',
                422,
            );
        }
    }

    /**
     * "Ověřit připojení": log in with every stored login (read-only calls), refresh privileges and password expiry.
     *
     * A login ISDS refused is tried again only after its wait time (5 min, then doubling), so a wrong password cannot be
     * hammered into an ISDS account lockout by pressing the button.
     *
     * @return array<string,mixed> public view, plus `check` with the per-login outcome
     */
    public function verify(int $supplierId, int $boxId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $box = $this->requireBox($supplierId, $boxId);
        $this->requireIsdsMode($box);
        $results = [];
        foreach ($box['credentials'] as $purpose => $cred) {
            if ($wait = $this->cooldownMessage($cred, $now)) {
                $results[$purpose] = ['ok' => false, 'reason' => 'cooldown', 'message' => $wait];
                continue;
            }
            try {
                // The sticky "refused" flag is exactly what this test is allowed to lift.
                $probe = $this->probe($this->accessFor($box, $purpose, true));
                $this->assertSameBox($box, $probe);
                $this->recordOk($boxId, $purpose, $probe);
                $results[$purpose] = ['ok' => true, 'message' => null];
            } catch (IsdsException|DataboxException $e) {
                // A refused login was already recorded by the access hook. Any other failure says nothing about the
                // password, so it must not overwrite (and so lift) the sticky "refused" flag of the stored login.
                $refusedByIsds = $e instanceof IsdsException && $e->isAuthFailure();
                if (!$refusedByIsds && !$this->isFlagged($cred)) {
                    $this->boxes->recordCheck($boxId, $purpose, 'error', $e->getMessage());
                }
                $results[$purpose] = ['ok' => false, 'reason' => $e instanceof IsdsException ? $e->reason : $e->errorCode, 'message' => $e->getMessage()];
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
    public function setCredential(int $supplierId, int $boxId, string $purpose, string $username, #[\SensitiveParameter] string $password, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $this->assertPurpose($purpose);
        $box = $this->requireBox($supplierId, $boxId);
        $this->requireIsdsMode($box);
        $stored = $box['credentials'][$purpose] ?? null;
        if ($stored !== null && $wait = $this->cooldownMessage($stored, $now)) {
            throw new DataboxException('cooldown', $wait, 429);
        }
        $login = $this->requireLogin(['username' => $username, 'password' => $password], 'Přihlášení');
        try {
            $probe = $this->probe(new IsdsAccess($box['environment'], $login));
        } catch (IsdsException $e) {
            // The typed replacement was refused: count it as an attempt (wait time) without flagging the stored login.
            if ($e->isAuthFailure() && $stored !== null) {
                $this->boxes->registerAuthFailure($boxId, $purpose, null, $e->getMessage(), $now, $e->blockedUntil($now));
            }
            throw $e;
        }
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
        $this->requireIsdsMode($this->requireBox($supplierId, $boxId));
        $this->boxes->deleteCredential($boxId, $purpose);
        return $this->present($this->boxes->find($boxId));
    }

    /**
     * @param array{label?:string, is_active?:bool, allow_delivering_sync?:bool, accountant_visible?:bool} $fields
     * @return array<string,mixed> public view
     */
    public function update(int $supplierId, int $boxId, array $fields): array
    {
        $box = $this->requireBox($supplierId, $boxId);
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
        if (isset($fields['accountant_visible'])) {
            $update['accountant_visible'] = (bool) $fields['accountant_visible'];
        }
        if (isset($fields['allow_delivering_sync'])) {
            $this->requireIsdsMode($box);
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

    /**
     * Decrypt one login of the box and bind the lockout protection to it: whatever the caller does with the returned
     * access, a refused login is recorded against this stored login (see {@see registerAuthFailure()}).
     *
     * A login ISDS refused earlier is not handed out at all until {@see verify()} succeeds; repeated wrong passwords lock
     * the ISDS account.
     *
     * @param array<string,mixed> $box
     * @param bool $ignoreFlag only for the connection test that is meant to lift the flag
     * @throws DataboxException
     */
    public function accessFor(array $box, string $purpose, bool $ignoreFlag = false): IsdsAccess
    {
        $this->requireIsdsMode($box);
        $cred = $box['credentials'][$purpose] ?? null;
        if ($cred === null) {
            throw new DataboxException('no_login', 'Pro tuto datovou schránku není uloženo požadované přihlášení.');
        }
        if (!$ignoreFlag && $this->isFlagged($cred)) {
            throw $this->refusedLogin();
        }
        try {
            $password = $this->crypto->decrypt((string) $cred['password_enc']);
        } catch (\RuntimeException) {
            throw new DataboxException('credential_unreadable', 'Uložené heslo se nepodařilo dešifrovat (změnil se šifrovací klíč aplikace). Zadejte přihlášení znovu.', 500);
        }
        $boxId = (int) $box['id'];
        return new IsdsAccess(
            $box['environment'],
            new IsdsCredentials((string) $cred['username'], $password),
            fn (IsdsException $e) => $this->registerAuthFailure($boxId, $purpose, $e),
        );
    }

    /**
     * The login to use for an explicit user action, chosen by the right it needs. The 'action' login is preferred,
     * the sync login is the fallback. Unknown privileges are fetched first; logins ISDS refused are skipped.
     *
     * @param array<string,mixed> $box
     * @param callable(DataboxPrivileges):bool $needs
     * @throws DataboxException when no usable stored login has the right ("missingRight" is the Czech name of the right)
     */
    public function accessForRight(array $box, callable $needs, string $missingRight): IsdsAccess
    {
        $this->requireIsdsMode($box);
        $refused = false;
        foreach (['action', 'sync'] as $purpose) {
            if (!isset($box['credentials'][$purpose])) {
                continue;
            }
            if ($this->isFlagged($box['credentials'][$purpose])) {
                $refused = true;
                continue;
            }
            $privileges = $this->privilegesOf($box['credentials'][$purpose]) ?? $this->refreshRights($box, $purpose);
            if ($needs($privileges)) {
                return $this->accessFor($box, $purpose);
            }
        }
        if ($refused) {
            throw $this->refusedLogin();
        }
        throw new DataboxException(
            'missing_right',
            "Žádné z uložených přihlášení k této schránce nemá právo „{$missingRight}“. V nastavení datových schránek přidejte přihlášení s tímto právem.",
            422,
            ['missing_right' => $missingRight],
        );
    }

    /**
     * Re-read the rights of one stored login with the read-only GetUserInfoFromLogin2 (no privilege needed, never
     * delivers) and cache them. The background sync calls this before every run: rights are changed in the ISDS portal
     * without this application noticing.
     *
     * @param array<string,mixed> $box
     */
    public function refreshRights(array $box, string $purpose): DataboxPrivileges
    {
        $user = $this->isds->userInfo($this->accessFor($box, $purpose));
        $this->boxes->saveRights((int) $box['id'], $purpose, $user['user_type'], $user['privileges']);
        return DataboxPrivileges::fromUserInfo($user['user_type'], $user['privileges']);
    }

    private function refusedLogin(): DataboxException
    {
        return new DataboxException(
            'login_failed',
            'Systém datových schránek toto přihlášení odmítl, proto ho aplikace nepoužije, dokud ho v nastavení neověříte (Ověřit připojení) – opakované chybné pokusy účet zamknou.',
            422,
        );
    }

    /** @param array<string,mixed> $cred a databox_credentials row */
    public function isFlagged(array $cred): bool
    {
        return in_array($cred['last_check_status'], ['bad_credentials', 'locked'], true);
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

    /** Invoked by {@see IsdsClient} for every refused login of a stored credential, whoever made the call. */
    private function registerAuthFailure(int $boxId, string $purpose, IsdsException $e): void
    {
        $now = new \DateTimeImmutable();
        $this->boxes->registerAuthFailure(
            $boxId,
            $purpose,
            $e->reason === 'bad_credentials' ? 'bad_credentials' : 'locked',
            $e->getMessage(),
            $now,
            $e->blockedUntil($now),
        );
    }

    /** Czech notice when a manual login attempt must still wait, else null. @param array<string,mixed> $cred */
    private function cooldownMessage(array $cred, \DateTimeImmutable $now): ?string
    {
        $until = $cred['retry_after'] !== null ? new \DateTimeImmutable((string) $cred['retry_after']) : null;
        if ($until === null || $until <= $now) {
            return null;
        }
        return 'Poslední přihlášení bylo odmítnuto. Další pokus je možný po ' . $until->format('H:i')
            . ' – opakované chybné pokusy by účet v datových schránkách zamkly.';
    }

    /** @param mixed $login @throws DataboxException */
    private function requireLogin(#[\SensitiveParameter] mixed $login, string $what): IsdsCredentials
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

    /**
     * @param bool $accountantView the caller is the accountant role: a box not opened to her does not exist for her (404)
     * @return array<string,mixed> the box of this supplier with its logins
     * @throws DataboxException
     */
    public function requireBox(int $supplierId, int $boxId, bool $accountantView = false): array
    {
        $box = $this->boxes->find($boxId, $supplierId);
        if ($box === null || ($accountantView && !$box['accountant_visible'])) {
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
