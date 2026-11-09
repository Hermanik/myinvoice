<?php

declare(strict_types=1);

namespace MyInvoice\Action\Databox;

use MyInvoice\Http\Json;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailSettingsService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Watching the data boxes through the notification e-mails of the data box system (Settings -> Datové schránky) -
 * session + admin only, like every data box route.
 *
 *   GET  /api/databox/email-settings        mailbox, folder, alert address, last scan; the IMAP accounts to choose from
 *   PUT  /api/databox/email-settings        {imap_account_id, folder, enabled, alert_email}
 *   POST /api/databox/email-settings/scan   read the mailbox now and return the summary
 *
 * A box that is watched this way is connected with POST /api/databox/boxes {mode: 'email', db_id, db_type, ...}.
 * IMAP passwords never appear in any answer.
 */
final class DataboxEmailAction extends DataboxActionBase
{
    public function __construct(
        ActivityLogger $activity,
        IpMatcher $ipMatcher,
        private readonly DataboxEmailSettingsService $settings,
    ) {
        parent::__construct($activity, $ipMatcher);
    }

    public function get(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn (int $sid): Response => Json::ok($response, $this->settings->present($sid)));
    }

    public function update(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response): Response {
            $fields = array_intersect_key($this->body($request), array_flip(['imap_account_id', 'folder', 'enabled', 'alert_email']));
            $saved = $this->settings->save($sid, $fields);
            $this->audit($request, 'databox.email_settings_updated', null, ['enabled' => $saved['settings']['enabled'], 'fields' => array_keys($fields)]);
            return Json::ok($response, $saved);
        });
    }

    public function scan(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $response): Response {
            $result = $this->settings->scan($sid);
            $this->audit($request, 'databox.email_scanned', null, array_diff_key($result['summary'], ['details' => 1]));
            return Json::ok($response, $result);
        });
    }
}
