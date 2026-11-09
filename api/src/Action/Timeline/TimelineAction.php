<?php

declare(strict_types=1);

namespace MyInvoice\Action\Timeline;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ObligationTimelineRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Timeline\ObligationTimelineService;
use MyInvoice\Service\Timeline\StandingOrders;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Obligations timeline ("Harmonogram"):
 *
 *   GET    /api/timeline?from=&to=             computed items of the range (default: 30 days back .. end of next year)
 *   PUT    /api/timeline/marks                 {key, done, note?}  manual mark on a computed item
 *   DELETE /api/timeline/marks?key=            remove the mark
 *   POST   /api/timeline/custom-items          the user's own item
 *   PUT    /api/timeline/custom-items/{id}     edit / mark done
 *   DELETE /api/timeline/custom-items/{id}
 *   GET    /api/timeline/standing-orders       the regular payments the owner pays by standing order
 *   POST   /api/timeline/standing-orders       {type, amount, day_of_month, valid_from, valid_to?, note?}
 *   PUT    /api/timeline/standing-orders/{id}  edit
 *   DELETE /api/timeline/standing-orders/{id}
 *   GET    /api/timeline/settings              {invoicing_due, invoicing_working_day}
 *   PUT    /api/timeline/settings              change them
 *
 * Session only (not in the API-token allowlist) and for the roles admin and accountant — the timeline shows the
 * owner's tax situation and the marks are bookkeeping; readonly users do not get it ({@see \MyInvoice\Middleware\RoleMiddleware}
 * ACCOUNTANT_RULES, checked again here).
 */
final class TimelineAction
{
    private const KEY_PATTERN = '/^[a-z_]+(:[0-9A-Za-z_-]+){1,2}$/';
    private const MAX_ORDER_AMOUNT = 9999999.99;
    private const MAX_WORKING_DAY = 20;

    public function __construct(
        private readonly ObligationTimelineService $timeline,
        private readonly ObligationTimelineRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        $now = new \DateTimeImmutable('today');
        [$defaultFrom, $defaultTo] = ObligationTimelineService::defaultRange($now);
        $query = $request->getQueryParams();
        $from = (string) ($query['from'] ?? $defaultFrom);
        $to = (string) ($query['to'] ?? $defaultTo);
        if (!self::isDate($from) || !self::isDate($to) || $to < $from) {
            return Json::error($response, 'validation_failed', 'Neplatné období: od a do musí být data RRRR-MM-DD, do není před od.', 422);
        }
        if ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > ObligationTimelineService::MAX_RANGE_DAYS) {
            return Json::error($response, 'validation_failed', 'Období může mít nejvýše 5 let.', 422);
        }
        return Json::ok($response, $this->timeline->build(SupplierGuard::currentId($request), $now, $from, $to));
    }

    public function setMark(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, write: true)) !== null) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $key = trim((string) ($body['key'] ?? ''));
        $note = $this->optionalText($body['note'] ?? null);
        if (!self::isMarkKey($key) || str_starts_with($key, 'custom:')) {
            return Json::error($response, 'validation_failed', 'Neplatný klíč položky.', 422);
        }
        if ($note !== null && mb_strlen($note) > 500) {
            return Json::error($response, 'validation_failed', 'Poznámka může mít nejvýše 500 znaků.', 422);
        }
        $done = !array_key_exists('done', $body) || filter_var($body['done'], FILTER_VALIDATE_BOOLEAN);
        $supplierId = SupplierGuard::currentId($request);

        $this->repo->saveMark($supplierId, $key, $done, $note, $this->userId($request));
        $this->log($request, 'timeline.mark_saved', $supplierId, ['key' => $key, 'done' => $done]);
        return Json::ok($response, ['key' => $key, 'mark' => $this->repo->marks($supplierId)[$key] ?? null]);
    }

    public function clearMark(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, write: true)) !== null) {
            return $denied;
        }
        $key = trim((string) ($request->getQueryParams()['key'] ?? ''));
        if (!self::isMarkKey($key)) {
            return Json::error($response, 'validation_failed', 'Neplatný klíč položky.', 422);
        }
        $supplierId = SupplierGuard::currentId($request);
        $this->repo->deleteMark($supplierId, $key);
        $this->log($request, 'timeline.mark_cleared', $supplierId, ['key' => $key]);
        return Json::ok($response, ['key' => $key, 'mark' => null]);
    }

    public function createCustom(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, write: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $userId = $this->userId($request);
        [$data, $errors] = $this->parseCustom((array) ($request->getParsedBody() ?? []), null, $userId);
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }
        $id = $this->repo->createCustom($supplierId, $data, $userId);
        $this->log($request, 'timeline.custom_created', $supplierId, ['id' => $id, 'title' => $data['title'], 'due_date' => $data['due_date']]);
        return Json::ok($response, $this->publicCustom($this->repo->findCustom($supplierId, $id)), 201);
    }

    public function updateCustom(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, write: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->findCustom($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Položka nenalezena.', 404);
        }
        [$data, $errors] = $this->parseCustom((array) ($request->getParsedBody() ?? []), $existing, $this->userId($request));
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }
        $this->repo->updateCustom($supplierId, $id, $data);
        $this->log($request, 'timeline.custom_updated', $supplierId, ['id' => $id, 'done' => $data['done_at'] !== null]);
        return Json::ok($response, $this->publicCustom($this->repo->findCustom($supplierId, $id)));
    }

    public function deleteCustom(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, write: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->repo->deleteCustom($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Položka nenalezena.', 404);
        }
        $this->log($request, 'timeline.custom_deleted', $supplierId, ['id' => $id]);
        return Json::ok($response, ['id' => $id]);
    }

    // ── standing orders and settings ────────────────────────────────────────

    public function listOrders(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        return Json::ok($response, ['items' => $this->repo->standingOrders(SupplierGuard::currentId($request))]);
    }

    public function createOrder(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, payments: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        [$data, $errors] = $this->parseOrder((array) ($request->getParsedBody() ?? []), null);
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }
        $id = $this->repo->createStandingOrder($supplierId, $data, $this->userId($request));
        $this->log($request, 'timeline.order_created', $supplierId, ['id' => $id, 'type' => $data['type'], 'amount' => $data['amount']]);
        return Json::ok($response, $this->repo->findStandingOrder($supplierId, $id), 201);
    }

    public function updateOrder(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, payments: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->findStandingOrder($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Položka nenalezena.', 404);
        }
        [$data, $errors] = $this->parseOrder((array) ($request->getParsedBody() ?? []), $existing);
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }
        $this->repo->updateStandingOrder($supplierId, $id, $data);
        $this->log($request, 'timeline.order_updated', $supplierId, ['id' => $id, 'type' => $data['type'], 'amount' => $data['amount']]);
        return Json::ok($response, $this->repo->findStandingOrder($supplierId, $id));
    }

    public function deleteOrder(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->guard($request, $response, payments: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if (!$this->repo->deleteStandingOrder($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Položka nenalezena.', 404);
        }
        $this->log($request, 'timeline.order_deleted', $supplierId, ['id' => $id]);
        return Json::ok($response, ['id' => $id]);
    }

    public function getSettings(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response)) !== null) {
            return $denied;
        }
        return Json::ok($response, $this->repo->settings(SupplierGuard::currentId($request)));
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (($denied = $this->guard($request, $response, payments: true)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $current = $this->repo->settings($supplierId);
        $due = (string) ($body['invoicing_due'] ?? $current['invoicing_due']);
        $day = $body['invoicing_working_day'] ?? $current['invoicing_working_day'];
        $errors = [];
        if (!in_array($due, [ObligationTimelineRepository::INVOICING_MONTH_END, ObligationTimelineRepository::INVOICING_WORKING_DAY], true)) {
            $errors['invoicing_due'] = ['Neplatná hodnota.'];
        }
        if (filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_WORKING_DAY]]) === false) {
            $errors['invoicing_working_day'] = ['Pracovní den musí být číslo od 1 do ' . self::MAX_WORKING_DAY . '.'];
        }
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }
        $this->repo->saveSettings($supplierId, $due, (int) $day);
        $this->log($request, 'timeline.settings_saved', $supplierId, ['invoicing_due' => $due, 'invoicing_working_day' => (int) $day]);
        return Json::ok($response, $this->repo->settings($supplierId));
    }

    /**
     * Fields of a standing order from the request body; on update the missing fields keep the stored value.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0: array<string,mixed>, 1: array<string,list<string>>} [data, field errors]
     */
    private function parseOrder(array $body, ?array $existing): array
    {
        $pick = static fn (string $field): mixed => self::pick($body, $existing, $field);
        $errors = [];

        $type = (string) $pick('type');
        if (!in_array($type, StandingOrders::TYPES, true)) {
            $errors['type'] = ['Neplatný druh platby.'];
        }
        $amount = $pick('amount');
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > self::MAX_ORDER_AMOUNT) {
            $errors['amount'] = ['Částka musí být kladné číslo.'];
        }
        $day = filter_var($pick('day_of_month'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
        if ($day === false) {
            $errors['day_of_month'] = ['Den v měsíci musí být číslo od 1 do 31.'];
        }
        $validFrom = (string) $pick('valid_from');
        if (!self::isDate($validFrom)) {
            $errors['valid_from'] = ['Platnost od musí být datum RRRR-MM-DD.'];
        }
        $validTo = $this->optionalText($pick('valid_to'));
        if ($validTo !== null && (!self::isDate($validTo) || (self::isDate($validFrom) && $validTo < $validFrom))) {
            $errors['valid_to'] = ['Platnost do musí být datum RRRR-MM-DD, ne před začátkem.'];
        }
        $note = $this->optionalText($pick('note'));
        if ($note !== null && mb_strlen($note) > 255) {
            $errors['note'] = ['Poznámka může mít nejvýše 255 znaků.'];
        }

        return [[
            'type' => $type, 'amount' => round((float) $amount, 2), 'day_of_month' => (int) $day,
            'valid_from' => $validFrom, 'valid_to' => $validTo, 'note' => $note,
        ], $errors];
    }

    /**
     * Fields of a custom item from the request body; on update the missing fields keep the stored value.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0: array<string,mixed>, 1: array<string,list<string>>} [data, field errors]
     */
    private function parseCustom(array $body, ?array $existing, ?int $userId): array
    {
        $pick = static fn (string $field): mixed => self::pick($body, $existing, $field);
        $errors = [];

        $title = trim((string) $pick('title'));
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['title'] = ['Název je povinný, nejvýše 255 znaků.'];
        }
        $due = (string) $pick('due_date');
        if (!self::isDate($due)) {
            $errors['due_date'] = ['Termín musí být datum RRRR-MM-DD.'];
        }
        $start = $this->optionalText($pick('start_date'));
        if ($start !== null && (!self::isDate($start) || (self::isDate($due) && $start > $due))) {
            $errors['start_date'] = ['Začátek musí být datum RRRR-MM-DD, ne po termínu.'];
        }
        $category = (string) ($pick('category') ?? 'custom');
        if (!in_array($category, ObligationTimelineRepository::CATEGORIES, true)) {
            $errors['category'] = ['Neplatná kategorie.'];
        }
        $note = $this->optionalText($pick('note'));
        if ($note !== null && mb_strlen($note) > 1000) {
            $errors['note'] = ['Poznámka může mít nejvýše 1000 znaků.'];
        }
        $url = $this->optionalText($pick('url'));
        // Rendered as a link: only an internal path or an http(s) URL, never javascript: and the like.
        if ($url !== null && (strlen($url) > 500 || preg_match('#^(/(?![/\\\\])|https?://)\S*$#i', $url) !== 1)) {
            $errors['url'] = ['Odkaz musí být cesta v aplikaci (/…) nebo adresa http(s).'];
        }

        // Done: the stored completion stays while the flag does not change, it is stamped when the flag flips on.
        $wasDone = ($existing['done_at'] ?? null) !== null;
        $done = array_key_exists('done', $body) ? filter_var($body['done'], FILTER_VALIDATE_BOOLEAN) : $wasDone;
        [$doneAt, $doneBy] = match (true) {
            !$done => [null, null],
            $wasDone => [$existing['done_at'], $existing['done_by_id'] ?? null],
            default => [date('Y-m-d H:i:s'), $userId],
        };

        return [[
            'title' => $title, 'due_date' => $due, 'start_date' => $start, 'category' => $category,
            'note' => $note, 'url' => $url, 'done_at' => $doneAt, 'done_by' => $doneBy,
        ], $errors];
    }

    /**
     * @param array<string,mixed>|null $item
     * @return array<string,mixed>|null
     */
    private function publicCustom(?array $item): ?array
    {
        return $item === null ? null : array_diff_key($item, ['done_by_id' => 1]);
    }

    /**
     * A field of the request body, or the stored value when the body does not carry it.
     *
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     */
    private static function pick(array $body, ?array $existing, string $field): mixed
    {
        return array_key_exists($field, $body) ? $body[$field] : ($existing[$field] ?? null);
    }

    /** @param array<string,list<string>> $errors */
    private function invalid(Response $response, array $errors): Response
    {
        return Json::error($response, 'validation_failed', 'Validace selhala', 422, ['fields' => $errors]);
    }

    private function optionalText(mixed $value): ?string
    {
        $text = $value === null ? '' : trim((string) $value);
        return $text === '' ? null : $text;
    }

    private static function isMarkKey(string $key): bool
    {
        return strlen($key) <= 120 && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    /**
     * admin and accountant only. The writes need migration 9011; `$payments` marks a write of the standing orders or
     * the settings (migration 9012).
     */
    private function guard(Request $request, Response $response, bool $write = false, bool $payments = false): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array($user['role'] ?? '', ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        if (SupplierGuard::currentId($request) <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        if (($write || $payments) && !($this->repo->isAvailable() && (!$payments || $this->repo->hasPaymentTables()))) {
            return Json::error($response, 'migration_required', 'Harmonogram není dostupný — spusťte databázové migrace (php api/bin/migrate.php).', 409);
        }
        return null;
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $supplierId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'supplier',
            $supplierId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
