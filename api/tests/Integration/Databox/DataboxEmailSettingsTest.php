<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Action\Databox\DataboxBoxAction;
use MyInvoice\Action\Databox\DataboxEmailAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Repository\DataboxEmailNoticeRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailNoticeAlert;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailNoticeScanner;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailSettingsService;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNotice;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeAuthenticator;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeParser;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;
use MyInvoice\Tests\Support\Databox\FakeNoticeMailbox;
use MyInvoice\Tests\Support\Databox\RecordingEmailNoticeAlert;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\FixedClock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The settings page of the e-mail watcher and its HTTP layer: validation, what leaves the API (never an IMAP password),
 * the manual scan and the role gate.
 */
final class DataboxEmailSettingsTest extends DataboxIntegrationTestCase
{
    private const MARKER = '__databox_email_settings__';
    private const PASSWORD = 'imap-secret-never-returned';

    private DataboxEmailNoticeRepository $notices;
    private BankEmailNoticeRepository $imapAccounts;
    private DataboxEmailSettingsService $settings;
    private DataboxEmailAction $action;
    private DataboxBoxAction $boxAction;
    private FakeNoticeMailbox $mailbox;
    private int $accountId = 0;
    private bool $ready = false;
    /** @var array<string,mixed>|null */
    private ?array $previousSettings = null;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'databox_email_settings'")->fetch() === false) {
            $this->markTestSkipped('Migration 9009 (data box e-mail notices) was not applied.');
        }
        $this->notices = new DataboxEmailNoticeRepository($this->db);
        $this->imapAccounts = new BankEmailNoticeRepository($this->db, $this->crypto);
        $previous = $pdo->prepare('SELECT * FROM databox_email_settings WHERE supplier_id = ?');
        $previous->execute([$this->supplierId]);
        $this->previousSettings = $previous->fetch(\PDO::FETCH_ASSOC) ?: null;
        $this->cleanup();

        $this->accountId = (int) $this->imapAccounts->saveImapAccount($this->supplierId, [
            'name' => self::MARKER, 'host' => 'imap.example.invalid', 'encryption' => 'ssl', 'username' => 'owner@example.invalid', 'password' => self::PASSWORD,
        ])['id'];
        $this->mailbox = new FakeNoticeMailbox();
        $scanner = new DataboxEmailNoticeScanner(
            $this->notices, $this->imapAccounts, $this->mailbox, new DatovkaNoticeParser(),
            new DatovkaNoticeAuthenticator(new EmailAuthenticationVerifier(new DkimVerifier(new ArrayDkimKeyResolver(), new FixedClock('2026-11-09 10:00:00 UTC')))),
            $this->boxes, $this->messages, new RecordingEmailNoticeAlert(), new NullLogger(),
        );
        $this->settings = new DataboxEmailSettingsService($this->notices, $this->imapAccounts, $scanner);

        $activity = $this->createStub(ActivityLogger::class);
        $ip = $this->createStub(IpMatcher::class);
        $ip->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $this->action = new DataboxEmailAction($activity, $ip, $this->settings);
        $this->boxAction = new DataboxBoxAction($activity, $ip, $this->service, $this->messageService, $this->sync, $this->client);
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->cleanup();
            if ($this->previousSettings !== null) {
                $p = $this->previousSettings;
                $this->notices->saveSettings((int) $p['supplier_id'], $p['imap_account_id'] !== null ? (int) $p['imap_account_id'] : null, (string) $p['folder'], (bool) $p['enabled'], $p['alert_email']);
            }
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM databox_email_notices WHERE supplier_id = ? AND imap_account_id IN (SELECT id FROM bank_email_imap_settings WHERE name = ?)')
            ->execute([$this->supplierId, self::MARKER]);
        $pdo->prepare('DELETE FROM databox_email_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM bank_email_imap_settings WHERE supplier_id = ? AND name = ?')->execute([$this->supplierId, self::MARKER]);
    }

    private function request(string $method, string $uri, ?array $json = null, string $role = 'admin'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
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

    /** @return array<string,mixed> the data of an ok answer */
    private function data(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response)['data'] ?? $this->json($response);
    }

    public function testTheDefaultsAreADisabledWatcherAndTheImapAccountsToChooseFrom(): void
    {
        $view = $this->settings->present($this->supplierId);

        self::assertFalse($view['settings']['enabled']);
        self::assertNull($view['settings']['imap_account_id']);
        self::assertSame('INBOX', $view['settings']['folder']);
        self::assertNull($view['settings']['alert_email']);
        $account = array_values(array_filter($view['imap_accounts'], fn (array $a): bool => $a['id'] === $this->accountId))[0];
        self::assertSame(['id', 'name', 'host', 'username', 'has_password'], array_keys($account));
        self::assertTrue($account['has_password']);
    }

    public function testSavingKeepsWhatIsNotSent(): void
    {
        $this->settings->save($this->supplierId, ['imap_account_id' => $this->accountId, 'folder' => ' Datovka ', 'enabled' => true, 'alert_email' => 'me@example.invalid']);

        $view = $this->settings->save($this->supplierId, ['folder' => 'Datovka/2026']);

        self::assertSame($this->accountId, $view['settings']['imap_account_id']);
        self::assertSame('Datovka/2026', $view['settings']['folder']);
        self::assertTrue($view['settings']['enabled']);
        self::assertSame('me@example.invalid', $view['settings']['alert_email']);

        $cleared = $this->settings->save($this->supplierId, ['alert_email' => '  ']);
        self::assertNull($cleared['settings']['alert_email']);
    }

    public function testTheInputIsValidated(): void
    {
        foreach ([
            'enabling without a mailbox' => ['enabled' => true],
            'a mailbox that does not exist' => ['imap_account_id' => 987654321],
            'an empty folder' => ['imap_account_id' => $this->accountId, 'folder' => '  '],
            'a folder with a control character' => ['folder' => "In\nbox"],
            'a bad alert address' => ['alert_email' => 'not an address'],
        ] as $case => $input) {
            try {
                $this->settings->save($this->supplierId, $input);
                self::fail("{$case} was accepted");
            } catch (DataboxException $e) {
                self::assertSame('validation_failed', $e->errorCode, $case);
            }
        }
        self::assertFalse($this->notices->settings($this->supplierId)['enabled']);
    }

    public function testAnotherSuppliersMailboxCannotBeChosen(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has a single supplier.');
        }
        $foreign = $this->imapAccounts->saveImapAccount($this->otherSupplierId, ['name' => self::MARKER, 'host' => 'imap.example.invalid', 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p']);
        try {
            $this->expectException(DataboxException::class);
            $this->settings->save($this->supplierId, ['imap_account_id' => $foreign['id']]);
        } finally {
            $this->imapAccounts->deleteImapAccount($this->otherSupplierId, (int) $foreign['id']);
        }
    }

    public function testNoAnswerCarriesAnImapPassword(): void
    {
        $this->settings->save($this->supplierId, ['imap_account_id' => $this->accountId]);

        $answers = [
            $this->action->get($this->request('GET', '/api/databox/email-settings'), $this->response()),
            $this->action->update($this->request('PUT', '/api/databox/email-settings', ['folder' => 'Datovka']), $this->response()),
            $this->action->scan($this->request('POST', '/api/databox/email-settings/scan', []), $this->response()),
        ];
        foreach ($answers as $answer) {
            self::assertStringNotContainsString(self::PASSWORD, (string) $answer->getBody());
            self::assertStringNotContainsString('password_enc', (string) $answer->getBody());
        }
    }

    public function testTheManualScanReturnsTheSummaryWhetherOrNotTheCronIsEnabled(): void
    {
        $this->settings->save($this->supplierId, ['imap_account_id' => $this->accountId, 'folder' => 'Datovka', 'enabled' => false]);
        $this->mailbox->fill([F::raw(['message_id' => '<ignored@example.invalid>', 'subject' => 'Jiný e-mail', 'html' => "<p>Nic</p>\n"])]);

        $data = $this->data($this->action->scan($this->request('POST', '/api/databox/email-settings/scan', []), $this->response()));

        self::assertSame('ok', $data['summary']['status']);
        self::assertSame(1, $data['summary']['fetched']);
        self::assertSame(1, $data['summary']['ignored']);
        self::assertSame('ok', $data['settings']['last_scan_status']);
        self::assertSame('Datovka', $this->mailbox->reads[0]['settings']['folder']);
    }

    public function testTheActionsAreAdminOnly(): void
    {
        foreach (['accountant', 'readonly'] as $role) {
            self::assertSame(403, $this->action->get($this->request('GET', '/api/databox/email-settings', null, $role), $this->response())->getStatusCode(), $role);
            self::assertSame(403, $this->action->update($this->request('PUT', '/api/databox/email-settings', [], $role), $this->response())->getStatusCode(), $role);
            self::assertSame(403, $this->action->scan($this->request('POST', '/api/databox/email-settings/scan', [], $role), $this->response())->getStatusCode(), $role);
        }
    }

    public function testAValidationErrorIsA400WithTheCzechMessage(): void
    {
        $response = $this->action->update($this->request('PUT', '/api/databox/email-settings', ['alert_email' => 'nope']), $this->response());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
    }

    public function testABoxIsAddedInEmailModeThroughTheBoxEndpoint(): void
    {
        $response = $this->boxAction->create($this->request('POST', '/api/databox/boxes', [
            'mode' => 'email', 'label' => 'Moje schránka', 'db_id' => F::RECIPIENT_BOX, 'db_type' => 'PFO', 'owner_name' => 'Jana Testovací',
        ]), $this->response());

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $box = $this->json($response)['data'] ?? $this->json($response);
        $this->trackBox($box['id']);
        self::assertSame('email', $box['connection_mode']);
        self::assertSame(F::RECIPIENT_BOX, $box['db_id']);
        self::assertSame([], $box['credentials']);
        self::assertSame([], $this->isds->requests);

        $syncNow = $this->boxAction->syncNow($this->request('POST', '/api/databox/boxes/' . $box['id'] . '/sync', []), $this->response(), ['id' => (string) $box['id']]);
        self::assertSame('skipped', ($this->json($syncNow)['data'] ?? $this->json($syncNow))['report']['status']);
        $verify = $this->boxAction->verify($this->request('POST', '/api/databox/boxes/' . $box['id'] . '/verify', []), $this->response(), ['id' => (string) $box['id']]);
        self::assertSame(422, $verify->getStatusCode());
        self::assertSame('email_only', $this->json($verify)['error']['code']);
        self::assertSame([], $this->isds->requests);
    }

    public function testTheAlertShowsWhatArrivedAndThatItIsNotDeliveredYet(): void
    {
        $config = Config::load(dirname(__DIR__, 4));
        $alert = new DataboxEmailNoticeAlert((new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor(), $this->db, $config);
        $box = $this->connectEmailBox();
        $notice = new DatovkaNotice(DatovkaNotice::KIND_DATA, "Výzva\r\nBcc: attacker@example.invalid", 'Finanční úřad pro Testovací kraj', F::SENDER_BOX, 'Jana', F::RECIPIENT_BOX, F::DM_ID, null);

        $vars = $alert->variables($this->storedBox($box['id']), $notice, 17, new \DateTimeImmutable('2026-11-09 09:30:00'), true);

        self::assertSame('[Finanční úřad] Nová datová zpráva: Výzva Bcc: attacker@example.invalid', $vars['subject'], 'a single line, no header injection');
        self::assertSame('9. 11. 2026 09:30', $vars['arrived_at']);
        self::assertSame('19. 11. 2026', $vars['fiction_date']);
        self::assertSame('https://www.datovka.gov.cz', $vars['portal_link']);
        self::assertSame(F::RECIPIENT_BOX, $vars['box_db_id']);
        self::assertSame(F::DM_ID, $vars['dm_id']);
        $appUrl = rtrim((string) $config->get('app.url', ''), '/');
        self::assertSame($appUrl !== '' ? "{$appUrl}/databox?box={$box['id']}&message=17" : '', $vars['app_link']);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates/email'), ['autoescape' => 'html', 'strict_variables' => false]);
        $vars['locale'] = 'cs';
        foreach (['databox_new_message.cs.html.twig', 'databox_new_message.cs.txt.twig'] as $template) {
            $body = $twig->render($template, $vars);
            self::assertStringContainsString('zpráva zatím není doručena', mb_strtolower($body), $template);
            self::assertStringContainsString('19. 11. 2026', $body, $template);
            self::assertStringContainsString('https://www.datovka.gov.cz', $body, $template);
            self::assertStringContainsString(F::DM_ID, $body, $template);
            self::assertStringContainsString('od finančního úřadu', $body, $template);
        }
    }
}
