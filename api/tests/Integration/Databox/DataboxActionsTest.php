<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Action\Databox\DataboxBoxAction;
use MyInvoice\Action\Databox\DataboxMessageAction;
use MyInvoice\Action\Databox\DataboxSendAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Databox\IsdsTransportResponse;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;
use MyInvoice\Tests\Support\Databox\ZfoFixtures;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;

/**
 * The HTTP layer of the data box routes: role gate, JSON shapes, error mapping, uploads, downloads and the
 * guarantee that no secret leaves the API.
 */
final class DataboxActionsTest extends DataboxIntegrationTestCase
{
    private DataboxBoxAction $boxAction;
    private DataboxMessageAction $messageAction;
    private DataboxSendAction $sendAction;

    protected function setUp(): void
    {
        parent::setUp();
        $activity = $this->createStub(ActivityLogger::class);
        $ip = $this->createStub(IpMatcher::class);
        $ip->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $this->boxAction = new DataboxBoxAction($activity, $ip, $this->service, $this->messageService, $this->sync, $this->client);
        $this->messageAction = new DataboxMessageAction($activity, $ip, $this->messageService, $this->zfoImport, new DocumentStorage(\MyInvoice\Infrastructure\Config\Config::load(dirname(__DIR__, 4))));
        $this->sendAction = new DataboxSendAction($activity, $ip, $this->sender, $this->messageService, $this->submissions);
    }

    private function request(string $method, string $uri, ?array $json = null, string $role = 'admin'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        return $json !== null ? $request->withParsedBody($json) : $request;
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEveryActionRefusesNonAdminsExceptTheReadRoutesOfTheAccountant(): void
    {
        // Readonly users get nothing, the accountant only the read routes (see DataboxAccountantAccessTest).
        $reads = [
            fn (string $role) => $this->boxAction->list($this->request('GET', '/api/databox/boxes', null, $role), $this->response()),
            fn (string $role) => $this->messageAction->get($this->request('GET', '/api/databox/messages/1', null, $role), $this->response(), ['id' => '1']),
        ];
        foreach ($reads as $read) {
            self::assertSame(403, $read('readonly')->getStatusCode());
        }
        foreach (['accountant', 'readonly'] as $role) {
            $calls = [
                fn () => $this->boxAction->create($this->request('POST', '/api/databox/boxes', [], $role), $this->response()),
                fn () => $this->messageAction->open($this->request('POST', '/api/databox/messages/1/open', [], $role), $this->response(), ['id' => '1']),
                fn () => $this->messageAction->importZfo($this->request('POST', '/api/databox/import-zfo', [], $role), $this->response()),
                fn () => $this->sendAction->send($this->request('POST', '/api/databox/messages/send', [], $role), $this->response()),
                fn () => $this->sendAction->submissionPrefill($this->request('GET', '/api/databox/submissions/1/prefill', null, $role), $this->response(), ['id' => '1']),
            ];
            foreach ($calls as $call) {
                self::assertSame(403, $call()->getStatusCode(), $role);
            }
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testConnectingABoxNeverEchoesTheSecretsAndMapsLoginFailuresTo422(): void
    {
        $this->scriptLogin('owner_info_pfo', 'user_info_list_only');
        $created = $this->boxAction->create($this->request('POST', '/api/databox/boxes', [
            'label' => 'Podnikatelská',
            'sync' => ['username' => 'sync-user', 'password' => 'S3cret-sync-pass'],
        ]), $this->response());

        self::assertSame(201, $created->getStatusCode());
        $body = (string) $created->getBody();
        self::assertStringNotContainsString('S3cret-sync-pass', $body);
        $box = json_decode($body, true);
        self::assertSame('tpbxbx7', $box['db_id']);
        $this->service->remove($this->supplierId, $box['id'], true);

        $this->isds->queue('GetOwnerInfoFromLogin2', file_get_contents(DataboxFixtures::path('http_401_bad_password.html')), 401);
        $failed = $this->boxAction->create($this->request('POST', '/api/databox/boxes', ['sync' => ['username' => 'u', 'password' => 'wrong-pass']]), $this->response());
        self::assertSame(422, $failed->getStatusCode(), 'never 401: the SPA would treat it as a lost session');
        $error = $this->json($failed)['error'];
        self::assertSame('databox_login_failed', $error['code']);
        self::assertSame('bad_credentials', $error['reason']);
        self::assertStringNotContainsString('wrong-pass', (string) $failed->getBody());
    }

    public function testIsdsOutagesAreMappedToGatewayStatuses(): void
    {
        $this->isds->queue('GetOwnerInfoFromLogin2', 'down', 503);
        $maintenance = $this->boxAction->create($this->request('POST', '/api/databox/boxes', ['sync' => ['username' => 'u', 'password' => 'p']]), $this->response());
        self::assertSame(503, $maintenance->getStatusCode());
        self::assertSame('maintenance', $this->json($maintenance)['error']['reason']);

        $this->isds->queue('GetOwnerInfoFromLogin2', 'oops', 502);
        $gateway = $this->boxAction->create($this->request('POST', '/api/databox/boxes', ['sync' => ['username' => 'u', 'password' => 'p']]), $this->response());
        self::assertSame(502, $gateway->getStatusCode());
    }

    public function testListBoxesNeverContainsSecrets(): void
    {
        $box = $this->connectBox();
        $list = $this->json($this->boxAction->list($this->request('GET', '/api/databox/boxes'), $this->response()));
        self::assertContains($box['id'], array_column($list, 'id'));
        self::assertStringNotContainsString('S3cret', json_encode($list));
    }

    public function testSyncNowReportsAndRefusesLikeTheCron(): void
    {
        $box = $this->connectBox('user_info_reader');
        $response = $this->boxAction->syncNow($this->request('POST', "/api/databox/boxes/{$box['id']}/sync"), $this->response(), ['id' => (string) $box['id']]);
        $data = $this->json($response);
        self::assertSame('refused', $data['report']['status']);
        self::assertSame('blocked_delivers', $data['box']['sync']['state']);
        self::assertSame(0, $this->isds->count('GetListOfReceivedMessages'));
    }

    public function testLookupFindsABoxWithASearchingLogin(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $this->isds->queue('FindDataBox2', DataboxFixtures::xml('find_databox_found'));
        $response = $this->boxAction->lookup($this->request('GET', "/api/databox/boxes/{$box['id']}/lookup?q=7nyn2d9"), $this->response(), ['id' => (string) $box['id']]);
        $data = $this->json($response);
        self::assertSame('7nyn2d9', $data['boxes'][0]['db_id']);
        self::assertTrue($data['boxes'][0]['is_tax_office']);
        self::assertStringContainsString('<p:dbID>7nyn2d9</p:dbID>', $this->isds->bodyOf('FindDataBox2'));

        $noRight = $this->connectBox('user_info_list_only', null, 'owner_info_fo');
        $denied = $this->boxAction->lookup($this->request('GET', "/api/databox/boxes/{$noRight['id']}/lookup?q=abc"), $this->response(), ['id' => (string) $noRight['id']]);
        self::assertSame(422, $denied->getStatusCode());
        self::assertSame('missing_right', $this->json($denied)['error']['code']);
    }

    public function testZfoUploadReturnsOneResultPerFileAndNeverTouchesIsds(): void
    {
        $box = $this->connectEmailBox();
        $good = ZfoFixtures::seal(ZfoFixtures::messageXml(['sender' => ZfoFixtures::boxId('tstsnd'), 'recipient' => $box['db_id'], 'dm_id' => '8800020']));
        $files = [
            new UploadedFile($this->stream($good), '../DDZ_8800020.zfo', 'application/octet-stream', strlen($good), UPLOAD_ERR_OK),
            new UploadedFile($this->stream('not a zfo'), 'readme.txt', 'text/plain', 9, UPLOAD_ERR_OK),
            new UploadedFile($this->stream(''), 'broken.zfo', 'application/octet-stream', 0, UPLOAD_ERR_PARTIAL),
        ];

        $response = $this->messageAction->importZfo($this->request('POST', '/api/databox/import-zfo', [])->withUploadedFiles(['files' => $files]), $this->response());

        self::assertSame(200, $response->getStatusCode());
        $results = $this->json($response)['results'];
        self::assertSame(['created', 'rejected', 'rejected'], array_column($results, 'outcome'));
        self::assertSame('DDZ_8800020.zfo', $results[0]['name']);
        self::assertSame($box['id'], $results[0]['databox_id']);
        self::assertSame('Soubor se nepodařilo nahrát (příliš velký soubor nebo chyba přenosu).', $results[2]['reason']);
        self::assertNotNull($this->messages->findByDmId($box['id'], 'received', '8800020'));
        self::assertSame([], $this->isds->requests);
    }

    public function testZfoUploadWithoutAFileIsABadRequest(): void
    {
        $response = $this->messageAction->importZfo($this->request('POST', '/api/databox/import-zfo', []), $this->response());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
    }

    public function testComposeUploadConfirmAndDownloadThroughTheActions(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $file = new UploadedFile($this->stream("%PDF-1.4 upload\n"), 'Dopis finančnímu úřadu.pdf', 'application/pdf', 17, UPLOAD_ERR_OK);
        $prepare = $this->request('POST', '/api/databox/messages/prepare', [
            'box_id' => (string) $box['id'], 'recipient_box_id' => '7nyn2d9', 'annotation' => 'Dotaz',
        ])->withUploadedFiles(['files' => [$file]]);

        $prepared = $this->json($this->sendAction->prepare($prepare, $this->response()));
        self::assertSame('Dopis finančnímu úřadu.pdf', $prepared['summary']['files'][0]['name']);

        // The confirmation is mandatory: a send request without it is refused and sends nothing.
        $refused = $this->sendAction->send($this->request('POST', '/api/databox/messages/send', ['token' => $prepared['token']]), $this->response());
        self::assertSame(400, $refused->getStatusCode());
        self::assertSame('confirmation_required', $this->json($refused)['error']['code']);
        self::assertSame(0, $this->isds->count('CreateMessage'));

        $this->isds->queue('CreateMessage', DataboxFixtures::xml('create_message_ok'));
        $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt'));
        $zfo = DataboxFixtures::zfo('4102002', [['name' => 'Dopis finančnímu úřadu.pdf', 'mime' => 'application/pdf', 'bytes' => "%PDF-1.4 upload\n"]]);
        $this->isds->queue('SignedSentMessageDownload', DataboxFixtures::signedResponse('SignedSentMessageDownload', $zfo));
        $sent = $this->sendAction->send($this->request('POST', '/api/databox/messages/send', ['token' => $prepared['token'], 'confirmed' => true]), $this->response());

        self::assertSame(201, $sent->getStatusCode());
        $data = $this->json($sent);
        self::assertSame('4102002', $data['dm_id']);
        self::assertSame('sent', $data['message']['direction']);
        self::assertCount(1, $data['message']['attachments']);

        $id = (string) $data['message_id'];
        $attachment = $this->messageAction->attachment($this->request('GET', "/api/databox/messages/{$id}/attachments/0"), $this->response(), ['id' => $id, 'index' => '0']);
        self::assertSame(200, $attachment->getStatusCode());
        self::assertSame("%PDF-1.4 upload\n", (string) $attachment->getBody());
        self::assertSame('application/octet-stream', $attachment->getHeaderLine('Content-Type'), 'never rendered inline');
        self::assertSame('nosniff', $attachment->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(
            'attachment; filename="Dopis financnimu uradu.pdf"; filename*=UTF-8\'\'Dopis%20finan%C4%8Dn%C3%ADmu%20%C3%BA%C5%99adu.pdf',
            $attachment->getHeaderLine('Content-Disposition'),
        );

        $zfoResponse = $this->messageAction->zfo($this->request('GET', "/api/databox/messages/{$id}/zfo"), $this->response(), ['id' => $id]);
        self::assertSame($zfo, (string) $zfoResponse->getBody());
        $receipt = $this->messageAction->zfo($this->request('GET', "/api/databox/messages/{$id}/zfo?kind=delivery_zfo"), $this->response(), ['id' => $id]);
        self::assertSame('receipt', (string) $receipt->getBody());
        self::assertStringContainsString('dorucenka', $receipt->getHeaderLine('Content-Disposition'));
    }

    public function testOpeningAnUndeliveredMessageThroughTheActionNeedsTheAcknowledgement(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_reader');
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_empty'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $tax = (string) $this->messages->findByDmId($box['id'], 'received', '4101001')['id'];
        $this->forgetOperations();

        $refused = $this->messageAction->open($this->request('POST', "/api/databox/messages/{$tax}/open", []), $this->response(), ['id' => $tax]);
        self::assertSame(409, $refused->getStatusCode());
        $error = $this->json($refused)['error'];
        self::assertSame('delivery_ack_required', $error['code']);
        self::assertTrue($error['undelivered']);
        self::assertSame([], $this->isds->requests);
    }

    public function testSubmissionPrefillPrepareAndLinksThroughTheActions(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $submission = $this->archiveSubmission('dphdp3', self::epoXml('451'));
        $id = (string) $submission;

        $prefill = $this->json($this->sendAction->submissionPrefill($this->request('GET', "/api/databox/submissions/{$id}/prefill"), $this->response(), ['id' => $id]));
        self::assertSame('7nyn2d9', $prefill['recipient']['box_id']);

        $prepared = $this->json($this->sendAction->submissionPrepare($this->request('POST', "/api/databox/submissions/{$id}/prepare", ['box_id' => (string) $box['id']]), $this->response(), ['id' => $id]));
        self::assertSame('dphdp3-2026-09.xml', $prepared['summary']['files'][0]['name']);

        $links = $this->json($this->sendAction->submissionLinks($this->request('GET', "/api/databox/submissions/links?ids={$id},999999"), $this->response()));
        self::assertSame([], $links, 'nothing sent yet - an empty JSON object');
        self::assertSame('{}', (string) $this->sendAction->submissionLinks($this->request('GET', "/api/databox/submissions/links?ids={$id}"), $this->response())->getBody());
    }

    private function stream(string $content): Stream
    {
        $resource = fopen('php://temp', 'w+b');
        fwrite($resource, $content);
        rewind($resource);
        return new Stream($resource);
    }
}
