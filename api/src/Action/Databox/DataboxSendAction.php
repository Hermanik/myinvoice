<?php

declare(strict_types=1);

namespace MyInvoice\Action\Databox;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxMessageService;
use MyInvoice\Service\Databox\DataboxSendService;
use MyInvoice\Service\Databox\SubmissionDataboxService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Sending - session + admin only, two explicit steps (see {@see DataboxSendService}).
 *
 *   POST /api/databox/messages/prepare                multipart: box_id, recipient_box_id, annotation, [to_hands,
 *                                                     sender_ref_number, recipient_ref_number, personal_delivery], files[]
 *                                                     -> {token, expires_at, summary}   (nothing is sent)
 *   POST /api/databox/messages/send                   JSON {token, confirmed: true, resend_confirmed?, unvalidated_confirmed?}  -> the sent message
 *
 *   GET  /api/databox/submissions/{id}/prefill        recipient / annotation / file / boxes for an archived EPO XML
 *   POST /api/databox/submissions/{id}/prepare        JSON {box_id, recipient_box_id?, annotation?} -> {token, summary}
 *   GET  /api/databox/submissions/links?ids=1,2,3     data box messages that carried the given archived submissions
 */
final class DataboxSendAction extends DataboxActionBase
{
    public function __construct(
        ActivityLogger $activity,
        IpMatcher $ipMatcher,
        private readonly DataboxSendService $sender,
        private readonly DataboxMessageService $messages,
        private readonly SubmissionDataboxService $submissions,
    ) {
        parent::__construct($activity, $ipMatcher);
    }

    public function prepare(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($request, $response): Response {
            $body = $this->body($request);
            $files = [];
            $uploaded = $request->getUploadedFiles()['files'] ?? [];
            foreach (is_array($uploaded) ? $uploaded : [$uploaded] as $file) {
                if (!$file instanceof UploadedFileInterface) {
                    continue;
                }
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new DataboxException('upload_failed', 'Přílohu se nepodařilo nahrát (příliš velký soubor nebo chyba přenosu).', 400);
                }
                $files[] = ['name' => (string) $file->getClientFilename(), 'bytes' => (string) $file->getStream()];
            }
            $prepared = $this->sender->prepare($sid, $uid, (int) ($body['box_id'] ?? 0), [
                'recipient_box_id' => (string) ($body['recipient_box_id'] ?? ''),
                'annotation' => (string) ($body['annotation'] ?? ''),
                'to_hands' => $body['to_hands'] ?? null,
                'sender_ref_number' => $body['sender_ref_number'] ?? null,
                'recipient_ref_number' => $body['recipient_ref_number'] ?? null,
                'personal_delivery' => filter_var($body['personal_delivery'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ], $files);
            return Json::ok($response, $prepared);
        });
    }

    public function send(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($request, $response): Response {
            $body = $this->body($request);
            $result = $this->sender->send(
                $sid,
                $uid,
                (string) ($body['token'] ?? ''),
                ($body['confirmed'] ?? false) === true,
                ($body['resend_confirmed'] ?? false) === true,
                unvalidatedConfirmed: ($body['unvalidated_confirmed'] ?? false) === true,
            );
            $this->audit($request, 'databox.message_sent', $result['message_id'], [
                'dm_id' => $result['dm_id'], 'submission_id' => $result['submission_id'],
            ]);
            return Json::ok($response, $result + ['message' => $this->messages->detail($sid, $result['message_id'])], 201);
        });
    }

    public function submissionPrefill(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn (int $sid): Response => Json::ok($response, $this->submissions->prefill($sid, (int) $args['id'])));
    }

    public function submissionPrepare(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid, int $uid) use ($request, $response, $args): Response {
            $body = $this->body($request);
            return Json::ok($response, $this->submissions->prepare($sid, $uid, (int) $args['id'], (int) ($body['box_id'] ?? 0), [
                'recipient_box_id' => $body['recipient_box_id'] ?? null,
                'annotation' => $body['annotation'] ?? null,
            ]));
        });
    }

    public function submissionLinks(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response): Response {
            $ids = array_values(array_filter(
                array_map('intval', explode(',', (string) ($request->getQueryParams()['ids'] ?? ''))),
                static fn (int $i): bool => $i > 0,
            ));
            return Json::ok($response, (object) $this->submissions->linksFor(array_slice($ids, 0, 200), $sid));
        });
    }
}
