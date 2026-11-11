<?php

declare(strict_types=1);

namespace MyInvoice\Action\Databox;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Databox\DataboxMessageService;
use MyInvoice\Service\Databox\DataboxZfoImportService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Stored messages of a box - session + admin only, except the four read routes (list, detail, attachment, zfo): the
 * accountant role gets those too, limited to the boxes flagged "visible to the accountant" (anything else is a 404).
 *
 *   GET  /api/databox/boxes/{id}/messages?direction=received|sent&unread=1&tax_office=1&q=&page=&per_page=
 *   GET  /api/databox/messages/{id}                     detail incl. what opening would do
 *   POST /api/databox/messages/{id}/open                download + store the signed message {acknowledge_delivery: bool}
 *   GET  /api/databox/messages/{id}/attachments/{index}
 *   GET  /api/databox/messages/{id}/zfo?kind=zfo|delivery_zfo
 *   POST /api/databox/messages/{id}/acknowledge
 *   POST /api/databox/messages/{id}/receipt             re-read the delivery receipt of a sent message
 *   POST /api/databox/import-zfo                        multipart files[]: signed messages / delivery receipts downloaded from the portal
 *                                                       -> {results:[{name, outcome, reason, ...}]}; verifies, never talks to ISDS
 *
 * Listing and the detail use only data stored by the (delivery-safe) sync. Only "open" talks to ISDS with a login
 * that can read, and it refuses to run for an undelivered message without {acknowledge_delivery: true}.
 */
final class DataboxMessageAction extends DataboxActionBase
{
    public function __construct(
        ActivityLogger $activity,
        IpMatcher $ipMatcher,
        private readonly DataboxMessageService $messages,
        private readonly DataboxZfoImportService $zfoImport,
        private readonly DocumentStorage $storage,
    ) {
        parent::__construct($activity, $ipMatcher);
    }

    public function list(Request $request, Response $response, array $args): Response
    {
        return $this->runRead($request, $response, function (int $sid, bool $accountantView) use ($request, $response, $args): Response {
            $q = $request->getQueryParams();
            $direction = in_array($q['direction'] ?? '', ['received', 'sent'], true) ? (string) $q['direction'] : null;
            return Json::ok($response, $this->messages->list($sid, isset($args['id']) ? (int) $args['id'] : null, [
                'direction' => $direction,
                'unread_only' => ($q['unread'] ?? '') === '1',
                'tax_office_only' => ($q['tax_office'] ?? '') === '1',
                'search' => (string) ($q['q'] ?? ''),
            ], (int) ($q['page'] ?? 1), (int) ($q['per_page'] ?? 50), $accountantView));
        });
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->runRead($request, $response, fn (int $sid, bool $accountantView): Response => Json::ok($response, $this->messages->detail($sid, (int) $args['id'], $accountantView)));
    }

    public function open(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($request, $response, $args): Response {
            $ack = (bool) ($this->body($request)['acknowledge_delivery'] ?? false);
            $detail = $this->messages->open($sid, (int) $args['id'], $uid, $ack);
            $this->audit($request, 'databox.message_opened', (int) $args['id'], ['dm_id' => $detail['dm_id'], 'acknowledged_delivery' => $ack]);
            return Json::ok($response, $detail);
        });
    }

    public function attachment(Request $request, Response $response, array $args): Response
    {
        return $this->runRead($request, $response, function (int $sid, bool $accountantView) use ($response, $args): Response {
            $file = $this->messages->attachment($sid, (int) $args['id'], (int) $args['index'], $accountantView);
            return $this->download($response, $this->storage->sanitizeFilename($file['name']), $file['bytes']);
        });
    }

    public function zfo(Request $request, Response $response, array $args): Response
    {
        return $this->runRead($request, $response, function (int $sid, bool $accountantView) use ($request, $response, $args): Response {
            $kind = ($request->getQueryParams()['kind'] ?? 'zfo') === 'delivery_zfo' ? 'delivery_zfo' : 'zfo';
            $file = $this->messages->file($sid, (int) $args['id'], $kind, $accountantView);
            return $this->download($response, $file['filename'], $file['bytes']);
        });
    }

    public function acknowledge(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($response, $args): Response {
            $this->messages->acknowledge($sid, (int) $args['id'], $uid);
            return Json::ok($response, ['acknowledged' => true]);
        });
    }

    public function refreshReceipt(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response, $args): Response {
            $detail = $this->messages->refreshReceipt($sid, (int) $args['id']);
            $this->audit($request, 'databox.receipt_refreshed', (int) $args['id'], ['dm_id' => $detail['dm_id']]);
            return Json::ok($response, $detail);
        });
    }

    public function importZfo(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($request, $response): Response {
            $files = [];
            $uploaded = $request->getUploadedFiles()['files'] ?? [];
            foreach (is_array($uploaded) ? $uploaded : [$uploaded] as $file) {
                if (!$file instanceof UploadedFileInterface) {
                    continue;
                }
                $name = (string) $file->getClientFilename();
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    $files[] = ['name' => $name, 'bytes' => null, 'problem' => 'Soubor se nepodařilo nahrát (příliš velký soubor nebo chyba přenosu).'];
                } elseif (($file->getSize() ?? 0) > DataboxZfoImportService::MAX_FILE_BYTES) {
                    $files[] = ['name' => $name, 'bytes' => null, 'problem' => 'Soubor je větší než 25 MB.'];
                } else {
                    $files[] = ['name' => $name, 'bytes' => (string) $file->getStream()];
                }
            }
            $results = $this->zfoImport->import($sid, $uid, $files);
            $this->audit($request, 'databox.zfo_imported', null, [
                'files' => count($results),
                'outcomes' => array_count_values(array_column($results, 'outcome')),
                'messages' => array_values(array_filter(array_column($results, 'dm_id'))),
            ]);
            return Json::ok($response, ['results' => $results]);
        });
    }

    /** Always a download, never rendered inline: attachments come from third parties. */
    private function download(Response $response, string $filename, string $bytes): Response
    {
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . addcslashes($this->asciiFallback($filename), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Plain `filename=` must be ASCII (RFC 6266); the exact name travels in `filename*`. */
    private function asciiFallback(string $name): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '_', ExportFilename::transliterate($name));
    }
}
