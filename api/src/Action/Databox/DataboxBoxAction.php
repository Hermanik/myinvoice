<?php

declare(strict_types=1);

namespace MyInvoice\Action\Databox;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxMessageService;
use MyInvoice\Service\Databox\DataboxPrivileges;
use MyInvoice\Service\Databox\DataboxService;
use MyInvoice\Service\Databox\DataboxSyncService;
use MyInvoice\Service\Databox\IsdsClient;
use MyInvoice\Service\Databox\TaxOfficeCodebook;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Connected data boxes (Settings -> Datové schránky) - session + admin only.
 *
 *   GET    /api/databox/boxes                              list
 *   POST   /api/databox/boxes                              connect a box (box ID / type are resolved from the login;
 *                                                          {mode:'email', db_id, db_type, label?, owner_name?} watches it by notification e-mails only)
 *   GET    /api/databox/boxes/{id}
 *   PUT    /api/databox/boxes/{id}                         label, is_active, allow_delivering_sync
 *   DELETE /api/databox/boxes/{id}?confirm_delete_messages=1
 *   POST   /api/databox/boxes/{id}/verify                  "Ověřit připojení"
 *   PUT    /api/databox/boxes/{id}/credentials/{purpose}   replace / add a login (sync | action)
 *   DELETE /api/databox/boxes/{id}/credentials/{purpose}
 *   POST   /api/databox/boxes/{id}/sync                    run the (delivery-safe) synchronisation now
 *   POST   /api/databox/boxes/{id}/acknowledge-all
 *   GET    /api/databox/boxes/{id}/lookup?q=               find a recipient box (needs a login with the search right)
 *
 * Passwords are accepted on write and never returned.
 */
final class DataboxBoxAction extends DataboxActionBase
{
    public function __construct(
        ActivityLogger $activity,
        IpMatcher $ipMatcher,
        private readonly DataboxService $service,
        private readonly DataboxMessageService $messages,
        private readonly DataboxSyncService $sync,
        private readonly IsdsClient $isds,
    ) {
        parent::__construct($activity, $ipMatcher);
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn (int $sid): Response => Json::ok($response, $this->service->listPublic($sid)));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($response, $args): Response {
            return Json::ok($response, $this->service->present($this->service->requireBox($sid, (int) $args['id'])));
        });
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response): Response {
            $body = $this->body($request);
            $box = $this->service->add($sid, ($body['mode'] ?? 'isds') === 'email' ? [
                'mode' => 'email',
                'label' => (string) ($body['label'] ?? ''),
                'db_id' => (string) ($body['db_id'] ?? ''),
                'db_type' => (string) ($body['db_type'] ?? ''),
                'owner_name' => (string) ($body['owner_name'] ?? ''),
            ] : [
                'label' => (string) ($body['label'] ?? ''),
                'environment' => (string) ($body['environment'] ?? 'production'),
                'sync' => ['username' => (string) ($body['sync']['username'] ?? ''), 'password' => (string) ($body['sync']['password'] ?? '')],
                'action' => isset($body['action']) ? ['username' => (string) ($body['action']['username'] ?? ''), 'password' => (string) ($body['action']['password'] ?? '')] : null,
                'allow_delivering_sync' => (bool) ($body['allow_delivering_sync'] ?? false),
            ]);
            $this->audit($request, 'databox.connected', $box['id'], ['db_id' => $box['db_id'], 'db_type' => $box['db_type'], 'environment' => $box['environment'], 'mode' => $box['connection_mode']]);
            return Json::ok($response, $box, 201);
        });
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $body = $this->body($request);
            $fields = array_intersect_key($body, array_flip(['label', 'is_active', 'allow_delivering_sync']));
            $box = $this->service->update($sid, (int) $args['id'], $fields);
            $this->audit($request, 'databox.updated', $box['id'], array_diff_key($fields, ['label' => 1]));
            return Json::ok($response, $box);
        });
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $confirm = (string) ($request->getQueryParams()['confirm_delete_messages'] ?? '') === '1';
            $this->service->remove($sid, (int) $args['id'], $confirm);
            $this->audit($request, 'databox.removed', (int) $args['id']);
            return Json::ok($response, ['deleted' => true]);
        });
    }

    public function verify(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $box = $this->service->verify($sid, (int) $args['id']);
            $this->audit($request, 'databox.verified', $box['id']);
            return Json::ok($response, $box);
        });
    }

    public function setCredential(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $body = $this->body($request);
            $box = $this->service->setCredential($sid, (int) $args['id'], (string) $args['purpose'], (string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''));
            $this->audit($request, 'databox.credential_set', $box['id'], ['purpose' => $args['purpose']]);
            return Json::ok($response, $box);
        });
    }

    public function removeCredential(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $box = $this->service->removeCredential($sid, (int) $args['id'], (string) $args['purpose']);
            $this->audit($request, 'databox.credential_removed', $box['id'], ['purpose' => $args['purpose']]);
            return Json::ok($response, $box);
        });
    }

    public function syncNow(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $box = $this->service->requireBox($sid, (int) $args['id']);
            $report = $this->sync->syncBox($box);
            $this->audit($request, 'databox.synced', $box['id'], ['status' => $report['status'], 'received_new' => $report['received_new']]);
            return Json::ok($response, ['report' => $report, 'box' => $this->service->present($this->service->requireBox($sid, $box['id']))]);
        });
    }

    public function acknowledgeAll(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($request, $response, $args): Response {
            $boxId = isset($args['id']) ? (int) $args['id'] : null;
            $count = $this->messages->acknowledgeAll($sid, $boxId, $uid);
            $this->audit($request, 'databox.acknowledged_all', $boxId, ['count' => $count]);
            return Json::ok($response, ['acknowledged' => $count]);
        });
    }

    public function lookup(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $box = $this->service->requireBox($sid, (int) $args['id']);
            $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
            if ($q === '') {
                throw new DataboxException('validation_failed', 'Zadejte ID schránky, IČO nebo název.', 400);
            }
            $access = $this->service->accessForRight($box, static fn (DataboxPrivileges $p): bool => $p->canSearch(), 'Vyhledávat schránky');
            $criteria = preg_match('/^[a-z0-9]{7}$/i', $q) === 1 ? ['db_id' => strtolower($q)]
                : (preg_match('/^[0-9]{6,8}$/', $q) === 1 ? ['ic' => $q] : ['name' => $q]);
            $found = $this->isds->findDataBox($access, $criteria);
            return Json::ok($response, [
                'status_code' => $found['status_code'],
                'boxes' => array_map(static fn (array $b): array => $b + ['is_tax_office' => TaxOfficeCodebook::isTaxOfficeBox($b['db_id'])], $found['boxes']),
            ]);
        });
    }
}
