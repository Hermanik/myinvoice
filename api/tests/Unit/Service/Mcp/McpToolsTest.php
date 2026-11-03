<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use PHPUnit\Framework\TestCase;

/** Behaviour of the individual tools against a faked internal API. */
final class McpToolsTest extends TestCase
{
    use McpTestHarness;

    protected function setUp(): void
    {
        $this->api = new FakeApi();
    }

    // ---- reads ----------------------------------------------------------------------------

    public function testListInvoicesBuildsFilterQueryAndFlattensMonthGroups(): void
    {
        $this->api->on('GET /api/invoices', [200, $this->listing([$this->invoiceFixture(), $this->invoiceFixture(['id' => 8, 'varsymbol' => '2611002'])], 41)]);

        $result = $this->call('list_invoices', [
            'status' => ['issued', 'sent'], 'client_id' => 2, 'date_from' => '2026-11-01', 'date_to' => '2026-11-30',
            'query' => ' Test ', 'page' => 2, 'per_page' => 10,
        ]);

        self::assertFalse($result['isError']);
        $query = $this->api->calls[0]['query'];
        self::assertSame('issued,sent', $query['filter']['status']);
        self::assertSame('2', $query['filter']['client_id']);
        self::assertSame('2026-11-01', $query['filter']['date_from']);
        self::assertSame('Test', $query['q']);
        self::assertSame('2', $query['page']);
        self::assertSame('10', $query['per_page']);

        $structured = $result['structuredContent'];
        self::assertCount(2, $structured['invoices']);
        self::assertSame('2611001', $structured['invoices'][0]['number']);
        self::assertSame(['id' => 2, 'name' => 'Test Klient s.r.o.'], $structured['invoices'][0]['client']);
        self::assertSame(1210.0, $structured['invoices'][0]['outstanding']);
        self::assertSame(41, $structured['paging']['total']);
        // Text content mirrors structuredContent for clients that only read `content`.
        self::assertEquals($structured, json_decode($result['content'][0]['text'], true));
    }

    public function testListInvoicesRejectsUnknownStatus(): void
    {
        $result = $this->call('list_invoices', ['status' => 'bogus']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('bogus', $result['content'][0]['text']);
        self::assertSame([], $this->api->calls);
    }

    public function testGetInvoiceByNumberResolvesExactMatchAndOmitsSecrets(): void
    {
        $this->api->on('GET /api/invoices', [200, $this->listing([
            $this->invoiceFixture(['id' => 70, 'varsymbol' => '26110019']),
            $this->invoiceFixture(['id' => 7, 'varsymbol' => '2611001']),
        ])]);
        $this->api->on('GET /api/invoices/7', [200, $this->invoiceFixture()]);
        $this->api->on('GET /api/invoices/7/payments', [200, [
            'payments' => [['id' => 1, 'amount' => 100, 'paid_on' => '2026-11-02', 'source' => 'manual', 'bank_transaction_id' => 9]],
            'remaining' => 1110,
        ]]);

        $result = $this->call('get_invoice', ['number' => '2611001']);

        self::assertFalse($result['isError']);
        $invoice = $result['structuredContent']['invoice'];
        self::assertSame(7, $invoice['id']);
        self::assertSame('Konzultace', $invoice['items'][0]['description']);
        self::assertSame(21.0, $invoice['items'][0]['vat_rate_percent']);
        self::assertSame(100.0, $invoice['payments'][0]['amount']);
        self::assertSame(1110.0, $invoice['remaining_to_pay']);
        self::assertSame('https://faktury.example.test/invoices/7', $invoice['url']);

        $text = $result['content'][0]['text'];
        self::assertStringNotContainsString('PUBLIC-TOKEN-SECRET', $text);
        self::assertStringNotContainsString('APPROVAL-TOKEN-SECRET', $text);
        self::assertStringNotContainsString('public_token', $text);
        self::assertStringNotContainsString('approval_token', $text);
    }

    public function testGetInvoiceNeedsExactlyOneIdentifier(): void
    {
        foreach ([[], ['invoice_id' => 7, 'number' => '2611001']] as $args) {
            $result = $this->call('get_invoice', $args);
            self::assertTrue($result['isError']);
        }
        self::assertSame([], $this->api->calls);
    }

    public function testGetInvoiceByUnknownNumberIsToolError(): void
    {
        $this->api->on('GET /api/invoices', [200, $this->listing([])]);

        $result = $this->call('get_invoice', ['number' => '9999999']);

        self::assertTrue($result['isError']);
        self::assertSame('not_found', $result['structuredContent']['error']['code']);
        self::assertStringContainsString('9999999', $result['content'][0]['text']);
    }

    public function testListUnpaidInvoicesPutsOverdueFirstAndTotalsPerCurrency(): void
    {
        $day = static fn (string $offset): string => date('Y-m-d', strtotime($offset));
        $this->api->on('GET /api/invoices', [200, $this->listing([
            $this->invoiceFixture(['id' => 1, 'due_date' => '2099-01-01']),
            $this->invoiceFixture(['id' => 2, 'due_date' => $day('-3 days')]),
            $this->invoiceFixture(['id' => 3, 'due_date' => $day('-40 days'), 'currency' => 'EUR', 'amount_to_pay' => 100.0, 'paid_total' => 40.0]),
            $this->invoiceFixture(['id' => 4, 'due_date' => $day('today')]),
        ])]);

        $result = $this->call('list_unpaid_invoices', []);

        $rows = $result['structuredContent']['invoices'];
        self::assertSame([3, 2, 4, 1], array_column($rows, 'id'));
        self::assertSame([40, 3, 0, 0], array_column($rows, 'days_overdue'));
        self::assertSame([true, true, false, false], array_column($rows, 'overdue'), 'due today is not overdue by default');
        self::assertSame(4, $result['structuredContent']['total_count']);
        self::assertFalse($result['structuredContent']['truncated']);

        $totals = array_column($result['structuredContent']['totals'], null, 'currency');
        self::assertSame(3, $totals['CZK']['count']);
        self::assertSame(3630.0, $totals['CZK']['outstanding']);
        self::assertSame(1210.0, $totals['CZK']['overdue_outstanding']);
        self::assertSame(60.0, $totals['EUR']['outstanding']);
        self::assertSame(1, $totals['EUR']['overdue_count']);

        $queries = array_column($this->api->callsTo('GET', '/api/invoices'), 'query');
        self::assertCount(1, $queries, 'overdue is derived from one unpaid walk, not a second listing');
        self::assertSame('1', $queries[0]['filter']['unpaid_only']);
    }

    public function testListUnpaidInvoicesFollowsTheOverduePolicyForToday(): void
    {
        $this->config = ['invoices' => ['overdue_includes_today' => true]];
        $this->api->on('GET /api/invoices', [200, $this->listing([$this->invoiceFixture(['id' => 4, 'due_date' => date('Y-m-d')])])]);

        $row = $this->call('list_unpaid_invoices')['structuredContent']['invoices'][0];

        self::assertTrue($row['overdue']);
        self::assertSame(0, $row['days_overdue']);
    }

    public function testListUnpaidInvoicesOnlyOverdueDropsTheRest(): void
    {
        $this->api->on('GET /api/invoices', [200, $this->listing([
            $this->invoiceFixture(['id' => 1, 'due_date' => '2099-01-01']),
            $this->invoiceFixture(['id' => 2, 'due_date' => date('Y-m-d', strtotime('-3 days'))]),
        ])]);

        $result = $this->call('list_unpaid_invoices', ['only_overdue' => true]);

        self::assertSame([2], array_column($result['structuredContent']['invoices'], 'id'));
        self::assertSame(1, $result['structuredContent']['total_count']);
    }

    public function testListUnpaidInvoicesLimitCutsRowsButNotTotals(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->invoiceFixture(['id' => $i, 'due_date' => '2099-01-0' . $i]);
        }
        $this->api->on('GET /api/invoices', [200, $this->listing($rows)]);

        $result = $this->call('list_unpaid_invoices', ['limit' => 2]);

        self::assertCount(2, $result['structuredContent']['invoices']);
        self::assertSame(5, $result['structuredContent']['total_count']);
        self::assertSame(6050.0, $result['structuredContent']['totals'][0]['outstanding']);
    }

    public function testRevenueSummaryAggregatesByMonthCurrencyAndClient(): void
    {
        $this->api->on('GET /api/invoices', [200, $this->listing([
            $this->invoiceFixture(['id' => 1, 'month_bucket' => '2026-03', 'client_id' => 2, 'client_company_name' => 'A', 'total_without_vat' => 1000.0, 'total_vat' => 210.0, 'total_with_vat' => 1210.0]),
            $this->invoiceFixture(['id' => 2, 'month_bucket' => '2026-03', 'client_id' => 3, 'client_company_name' => 'B', 'total_without_vat' => 3000.0, 'total_vat' => 630.0, 'total_with_vat' => 3630.0]),
            $this->invoiceFixture(['id' => 3, 'month_bucket' => '2026-04', 'client_id' => 2, 'client_company_name' => 'A', 'total_without_vat' => -200.0, 'total_vat' => -42.0, 'total_with_vat' => -242.0]),
            $this->invoiceFixture(['id' => 4, 'month_bucket' => '2026-04', 'client_id' => 3, 'client_company_name' => 'B', 'currency' => 'EUR', 'total_without_vat' => 50.5, 'total_vat' => 0.0, 'total_with_vat' => 50.5]),
        ])]);

        $result = $this->call('revenue_summary', ['year' => 2026, 'by_client' => true, 'top_clients' => 1]);

        self::assertFalse($result['isError']);
        $query = $this->api->calls[0]['query']['filter'];
        self::assertSame('2026', $query['year']);
        self::assertSame('issued,sent,reminded,paid', $query['status']);
        self::assertSame('invoice,credit_note,tax_document', $query['type']);

        $data = $result['structuredContent'];
        self::assertCount(12, $data['months']);
        $march = $data['months'][2];
        self::assertSame('2026-03', $march['month']);
        self::assertSame(['currency' => 'CZK', 'invoices' => 2, 'without_vat' => 4000.0, 'vat' => 840.0, 'with_vat' => 4840.0], $march['totals'][0]);
        self::assertSame([], $data['months'][0]['totals']);
        self::assertEquals(
            [['currency' => 'CZK', 'invoices' => 3, 'without_vat' => 3800.0, 'vat' => 798.0, 'with_vat' => 4598.0], ['currency' => 'EUR', 'invoices' => 1, 'without_vat' => 50.5, 'vat' => 0.0, 'with_vat' => 50.5]],
            $data['year_total'],
        );
        self::assertSame(4, $data['invoices_counted']);
        self::assertCount(1, $data['clients']);
        self::assertSame('B', $data['clients'][0]['client']['name']);
        self::assertSame(2, $data['clients_total']);
    }

    public function testRevenueSummaryWithoutClientBreakdownOmitsIt(): void
    {
        $this->api->on('GET /api/invoices', [200, $this->listing([])]);

        $result = $this->call('revenue_summary', ['year' => 2026]);

        self::assertArrayNotHasKey('clients', $result['structuredContent']);
    }

    public function testListClientsProjectsAllowlistedFieldsOnly(): void
    {
        $this->api->on('GET /api/clients', [200, [
            'data' => [[
                'id' => 4, 'company_name' => 'Acme', 'ic' => '123', 'dic' => 'CZ123', 'main_email' => 'a@acme.test',
                'country_iso2' => 'CZ', 'currency_default' => 'CZK', 'is_customer' => true, 'is_vendor' => false,
                'archived_at' => null, 'invoice_count' => 3, 'revenue' => 5000, 'last_invoice_date' => '2026-11-01',
                'idoklad_id' => 99, 'fakturoid_id' => 77, 'note' => 'internal',
            ]],
            'meta' => ['total' => 1, 'page' => 1, 'per_page' => 25, 'pages' => 1, 'role_counts' => []],
        ]]);

        $result = $this->call('list_clients', ['query' => 'acme', 'role' => 'customers']);

        $client = $result['structuredContent']['clients'][0];
        self::assertSame('Acme', $client['name']);
        self::assertArrayNotHasKey('idoklad_id', $client);
        self::assertArrayNotHasKey('note', $client);
        self::assertSame('acme', $this->api->calls[0]['query']['q']);
        self::assertSame('customers', $this->api->calls[0]['query']['role']);
    }

    public function testListPurchaseInvoicesPassesFiltersAndFlattens(): void
    {
        $this->api->on('GET /api/purchase-invoices', [200, $this->listing([[
            'id' => 5, 'varsymbol' => 'PF-1', 'vendor_invoice_number' => 'V-9', 'document_kind' => 'invoice', 'status' => 'received',
            'vendor_id' => 6, 'vendor_company_name' => 'Dodavatel a.s.', 'vendor_ic' => '555', 'issue_date' => '2026-11-01',
            'due_date' => '2026-11-20', 'currency' => 'CZK', 'total_with_vat' => 121.0, 'amount_to_pay' => 121.0,
        ]])]);

        $result = $this->call('list_purchase_invoices', ['status' => 'received', 'unpaid_only' => true, 'vendor_id' => 6]);

        $filter = $this->api->calls[0]['query']['filter'];
        self::assertSame('received', $filter['status']);
        self::assertSame('1', $filter['unpaid_only']);
        self::assertSame('6', $filter['vendor_id']);
        self::assertArrayNotHasKey('overdue', $filter);
        $row = $result['structuredContent']['purchase_invoices'][0];
        self::assertSame('Dodavatel a.s.', $row['vendor']['name']);
        self::assertSame(121.0, $row['amount_to_pay']);
    }

    public function testGetSupplierSettingsExposesOnlyNonSecretFields(): void
    {
        $this->api->on('GET /api/settings/supplier', [200, [
            'id' => 1, 'company_name' => 'Firma s.r.o.', 'ic' => '123', 'dic' => 'CZ123', 'street' => 'Ulice 1', 'city' => 'Praha',
            'zip' => '11000', 'country_iso' => 'CZ', 'is_vat_payer' => true, 'default_currency' => 'CZK', 'default_vat_rate_id' => 1,
            'default_payment_due_days' => 14, 'email' => 'f@f.test',
            // Columns that must never reach the model, whatever their name:
            'smtp_password' => 'SMTP-SECRET', 'imap_password_enc' => 'IMAP-SECRET', 'anthropic_api_key' => 'KEY-SECRET',
            'idoklad_client_id' => 'IDOKLAD', 'fakturoid_email' => 'fa@fa.test', 'abo_client_number' => '999',
        ]]);
        $this->api->on('GET /api/codebooks/vat-rates', [200, [
            ['id' => 1, 'code' => 'CZ-21', 'rate_percent' => 21, 'country' => 'CZ', 'label_cs' => 'Základní 21 %', 'is_default' => true, 'is_reverse_charge' => false],
            ['id' => 4, 'code' => 'CZ-RC', 'rate_percent' => 0, 'country' => 'CZ', 'label_cs' => 'Reverse charge', 'is_default' => false, 'is_reverse_charge' => true],
        ]]);
        $this->api->on('GET /api/settings/currencies', [200, [
            ['id' => 1, 'code' => 'CZK', 'is_active' => true, 'is_default' => true, 'account_number' => '1000000005', 'bank_code' => '0100', 'iban' => null],
            ['id' => 2, 'code' => 'USD', 'is_active' => false, 'is_default' => false, 'account_number' => null, 'bank_code' => null, 'iban' => null],
        ]]);

        $result = $this->call('get_supplier_settings');

        self::assertFalse($result['isError']);
        $text = $result['content'][0]['text'];
        foreach (['SMTP-SECRET', 'IMAP-SECRET', 'KEY-SECRET', 'IDOKLAD', 'fa@fa.test', '999'] as $leak) {
            self::assertStringNotContainsString($leak, $text, $leak);
        }
        $data = $result['structuredContent'];
        self::assertSame('Firma s.r.o.', $data['company']['name']);
        self::assertSame([1, 4], array_column($data['vat_rates'], 'id'));
        self::assertTrue($data['vat_rates'][1]['is_reverse_charge']);
        $rateQuery = $this->api->callsTo('GET', '/api/codebooks/vat-rates')[0]['query'];
        self::assertSame(['country' => 'CZ', 'active_on' => date('Y-m-d')], $rateQuery, 'valid CZ rates are filtered by the endpoint');
        self::assertSame(['CZK'], array_column($data['currencies'], 'code'), 'only active currencies');
    }

    public function testListRecurringTemplatesProjectsRows(): void
    {
        $this->api->on('GET /api/recurring', [200, [
            'data' => [['id' => 1, 'name' => 'Hosting', 'status' => 'active', 'client_id' => 2, 'client_company_name' => 'Acme', 'frequency' => 'monthly',
                'next_run_date' => '2026-12-01', 'currency' => 'CZK', 'total_with_vat' => 7260, 'auto_issue' => true, 'auto_send_email' => false]],
            'meta' => ['total' => 1, 'page' => 1, 'per_page' => 25, 'pages' => 1, 'status_counts' => ['all' => 1, 'active' => 1]],
        ]]);

        $result = $this->call('list_recurring_templates', ['status' => 'active']);

        self::assertSame('active', $this->api->calls[0]['query']['status']);
        self::assertSame('Hosting', $result['structuredContent']['templates'][0]['name']);
        self::assertSame(7260.0, $result['structuredContent']['templates'][0]['total_with_vat']);
        self::assertSame(['all' => 1, 'active' => 1], $result['structuredContent']['status_counts']);
    }

    // ---- errors ---------------------------------------------------------------------------

    public function testAppErrorBecomesToolErrorWithCzechMessageAndFields(): void
    {
        $this->api->on('GET /api/clients/2', [200, ['id' => 2]]);   // the client exists, so the app's own error stands
        $this->api->on('POST /api/invoices', [400, ['error' => [
            'code' => 'validation_failed', 'message' => 'Validace selhala', 'fields' => ['items.0.quantity' => ['Množství nesmí být 0.']],
        ]]]);

        $result = $this->call('create_draft_invoice', ['client_id' => 2, 'items' => [['description' => 'x', 'quantity' => 0, 'unit_price_without_vat' => 1, 'vat_rate_id' => 1]]]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Validace selhala', $result['content'][0]['text']);
        self::assertStringContainsString('Množství nesmí být 0.', $result['content'][0]['text']);
        self::assertSame('validation_failed', $result['structuredContent']['error']['code']);
        self::assertSame(400, $result['structuredContent']['error']['http_status']);
    }

    public function testServerErrorDetailsAreNotForwarded(): void
    {
        $this->api->on('GET /api/clients', [500, ['message' => 'Slim Application Error', 'exception' => [['message' => 'SQLSTATE[HY000] secret dsn']]]]);

        $result = $this->call('list_clients');

        self::assertTrue($result['isError']);
        self::assertStringNotContainsString('SQLSTATE', $result['content'][0]['text']);
        self::assertStringContainsString('HTTP 500', $result['content'][0]['text']);
    }

    public function testInvalidArgumentsAreReportedTogether(): void
    {
        $result = $this->call('list_invoices', ['year' => '2026', 'foo' => 1, 'month' => 13]);

        self::assertTrue($result['isError']);
        $text = $result['content'][0]['text'];
        self::assertStringContainsString('year', $text);
        self::assertStringContainsString('foo', $text);
        self::assertStringContainsString('month', $text);
        self::assertSame([], $this->api->calls);
    }

    public function testSecretLookingKeysAreStrippedFromAnyToolOutput(): void
    {
        // A client record gaining a secret-looking column must not leak through a pass-through field.
        $this->api->on('GET /api/clients/2', [200, [
            'id' => 2, 'company_name' => 'Acme', 'revenue_by_year' => [['year' => 2026, 'total' => 1, 'api_token' => 'LEAK', 'webhook_secret' => 'LEAK2']],
        ]]);

        $result = $this->call('get_client', ['client_id' => 2]);

        self::assertStringNotContainsString('LEAK', $result['content'][0]['text']);
    }

    // ---- write tools ----------------------------------------------------------------------

    public function testCreateDraftInvoiceResolvesVatRateAndNeverIssues(): void
    {
        $this->api->on('GET /api/codebooks/vat-rates', [200, [
            ['id' => 4, 'rate_percent' => 21, 'country' => 'CZ', 'is_default' => false, 'is_reverse_charge' => true],
            ['id' => 1, 'rate_percent' => 21, 'country' => 'CZ', 'is_default' => true, 'is_reverse_charge' => false],
            ['id' => 2, 'rate_percent' => 12, 'country' => 'CZ', 'is_default' => false, 'is_reverse_charge' => false],
        ]]);
        $this->api->on('POST /api/invoices', [201, $this->invoiceFixture(['id' => 31, 'varsymbol' => null, 'status' => 'draft', 'payment_status' => null])]);

        $result = $this->call('create_draft_invoice', [
            'client_id' => 2, 'tax_date' => '2026-11-03', 'currency' => 'EUR', 'note_below_items' => 'Děkujeme',
            'items' => [
                ['description' => 'Práce', 'quantity' => 2, 'unit' => 'h', 'unit_price_without_vat' => 1500, 'vat_rate' => 21],
                ['description' => 'Licence', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 2],
            ],
        ]);

        self::assertFalse($result['isError']);
        self::assertSame('draft', $result['structuredContent']['invoice']['status']);
        self::assertNull($result['structuredContent']['invoice']['number']);

        self::assertSame([], $this->api->callsTo('GET', '/api/clients/2'), 'no extra existence check on the happy path');
        $rateQuery = $this->api->callsTo('GET', '/api/codebooks/vat-rates')[0]['query'];
        self::assertSame(['country' => 'CZ', 'active_on' => '2026-11-03'], $rateQuery);
        $posts = $this->api->callsTo('POST', '/api/invoices');
        self::assertCount(1, $posts);
        $body = $posts[0]['body'];
        self::assertSame('EUR', $body['currency']);
        self::assertSame(2, $body['client_id']);
        self::assertSame([1, 2], array_column($body['items'], 'vat_rate_id'));
        self::assertSame('ks', $body['items'][1]['unit'], 'unit defaults to ks');
        self::assertArrayNotHasKey('vat_rate', $body['items'][0]);
        // Draft only: no issue call whatsoever.
        self::assertSame([], array_filter($this->api->calls, static fn (array $c): bool => str_ends_with($c['path'], '/issue')));
    }

    public function testCreateDraftInvoiceNeedsAVatRatePerItem(): void
    {
        $result = $this->call('create_draft_invoice', ['client_id' => 2, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 1]]]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('vat_rate', $result['content'][0]['text']);
        self::assertSame([], $this->api->callsTo('POST', '/api/invoices'));
    }

    public function testCreateDraftInvoiceRejectsUnavailableVatRate(): void
    {
        $this->api->on('GET /api/codebooks/vat-rates', [200, [
            ['id' => 1, 'rate_percent' => 21, 'country' => 'CZ', 'is_default' => true, 'is_reverse_charge' => false],
        ]]);

        $result = $this->call('create_draft_invoice', ['client_id' => 2, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate' => 17]]]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('21', $result['content'][0]['text']);
        self::assertSame([], $this->api->callsTo('POST', '/api/invoices'));
    }

    public function testCreateDraftInvoiceUnknownClientIsClearError(): void
    {
        // The real endpoint reports an unknown client as an invalid currency; the tool names the cause.
        $this->api->on('POST /api/invoices', [400, ['error' => ['code' => 'validation_failed', 'message' => 'Validace selhala', 'fields' => ['currency_id' => ['Neplatné currency_id']]]]]);

        $result = $this->call('create_draft_invoice', ['client_id' => 999, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate_id' => 1]]]);

        self::assertTrue($result['isError']);
        self::assertSame('not_found', $result['structuredContent']['error']['code']);
        self::assertStringContainsString('#999', $result['content'][0]['text']);
    }

    public function testCreateDraftInvoiceRequiresItemsAndRejectsUnknownFields(): void
    {
        $noItems = $this->call('create_draft_invoice', ['client_id' => 2, 'items' => []]);
        $unknown = $this->call('create_draft_invoice', ['client_id' => 2, 'status' => 'issued', 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate' => 21]]]);

        self::assertTrue($noItems['isError']);
        self::assertTrue($unknown['isError']);
        self::assertStringContainsString('status', $unknown['content'][0]['text']);
        self::assertSame([], $this->api->calls);
    }

    public function testIssueInvoiceWithoutConfirmChangesNothingAndShowsPreview(): void
    {
        $this->api->on('GET /api/invoices/7', [200, $this->invoiceFixture(['status' => 'draft', 'varsymbol' => null])]);

        foreach ([[], ['confirm' => false]] as $extra) {
            $result = $this->call('issue_invoice', ['invoice_id' => 7] + $extra);

            self::assertTrue($result['isError']);
            self::assertSame('confirmation_required', $result['structuredContent']['error']['code']);
            self::assertStringContainsString('Test Klient s.r.o.', $result['content'][0]['text']);
            self::assertStringContainsString('confirm: true', $result['content'][0]['text']);
        }
        self::assertFalse($this->api->wroteAnything(), 'no POST without confirm: true');
    }

    public function testIssueInvoiceRejectsNonBooleanConfirm(): void
    {
        foreach (['true', 1, 'yes'] as $confirm) {
            $result = $this->call('issue_invoice', ['invoice_id' => 7, 'confirm' => $confirm]);

            self::assertTrue($result['isError']);
        }
        self::assertFalse($this->api->wroteAnything());
    }

    public function testIssueInvoiceWithConfirmPostsToIssueEndpoint(): void
    {
        $this->api->on('POST /api/invoices/7/issue', [200, $this->invoiceFixture(['status' => 'issued', 'varsymbol' => '2611005'])]);

        $result = $this->call('issue_invoice', ['invoice_id' => 7, 'confirm' => true]);

        self::assertFalse($result['isError']);
        self::assertTrue($result['structuredContent']['issued']);
        self::assertSame('2611005', $result['structuredContent']['invoice']['number']);
        self::assertCount(1, $this->api->callsTo('POST', '/api/invoices/7/issue'));
    }

    public function testMarkInvoicePaidWithoutConfirmChangesNothing(): void
    {
        $this->api->on('GET /api/invoices/7', [200, $this->invoiceFixture()]);

        $result = $this->call('mark_invoice_paid', ['invoice_id' => 7, 'amount' => 100]);

        self::assertTrue($result['isError']);
        self::assertSame('confirmation_required', $result['structuredContent']['error']['code']);
        self::assertFalse($this->api->wroteAnything());
    }

    public function testMarkInvoicePaidWithoutAmountUsesMarkPaidEndpoint(): void
    {
        $this->api->on('POST /api/invoices/7/mark-paid', [200, $this->invoiceFixture(['status' => 'paid', 'paid_at' => '2026-11-02'])]);

        $result = $this->call('mark_invoice_paid', ['invoice_id' => 7, 'confirm' => true, 'paid_at' => '2026-11-02']);

        self::assertFalse($result['isError']);
        self::assertTrue($result['structuredContent']['became_paid']);
        $posts = $this->api->callsTo('POST', '/api/invoices/7/mark-paid');
        self::assertSame(['paid_at' => '2026-11-02'], $posts[0]['body']);
    }

    public function testMarkInvoicePaidWithAmountRecordsPayment(): void
    {
        $this->api->on('POST /api/invoices/7/payments', [200, [
            'invoice' => $this->invoiceFixture(['paid_total' => 500.0, 'payment_status' => 'partially_paid']),
            'became_paid' => false, 'remaining' => 710,
        ]]);

        $result = $this->call('mark_invoice_paid', ['invoice_id' => 7, 'confirm' => true, 'amount' => 500.0, 'paid_at' => '2026-11-02']);

        self::assertFalse($result['isError']);
        self::assertFalse($result['structuredContent']['became_paid']);
        self::assertSame(710.0, $result['structuredContent']['remaining']);
        $posts = $this->api->callsTo('POST', '/api/invoices/7/payments');
        self::assertEquals(['amount' => 500, 'paid_on' => '2026-11-02'], $posts[0]['body']);
    }

    public function testReadOnlyTokenIsBlockedFromEveryWriteToolBeforeAnyApiCall(): void
    {
        $writes = [
            'create_draft_invoice' => ['client_id' => 2, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate' => 21]]],
            'issue_invoice'        => ['invoice_id' => 7, 'confirm' => true],
            'mark_invoice_paid'    => ['invoice_id' => 7, 'confirm' => true],
        ];
        foreach ($writes as $tool => $args) {
            $result = $this->call($tool, $args, $this->request('read'));

            self::assertTrue($result['isError'], $tool);
            self::assertSame('insufficient_scope', $result['structuredContent']['error']['code'], $tool);
            self::assertStringContainsString('read_write', $result['content'][0]['text'], $tool);
        }
        self::assertSame([], $this->api->calls);
    }

    public function testReadonlyRoleIsBlockedFromWriteToolsDespiteWriteScope(): void
    {
        $result = $this->call('issue_invoice', ['invoice_id' => 7, 'confirm' => true], $this->request('read_write', 'readonly'));

        self::assertTrue($result['isError']);
        self::assertStringContainsString('role', $result['content'][0]['text']);
        self::assertSame([], $this->api->calls);
    }

    public function testAccountantMayWrite(): void
    {
        $this->api->on('POST /api/invoices/7/issue', [200, $this->invoiceFixture()]);

        $result = $this->call('issue_invoice', ['invoice_id' => 7, 'confirm' => true], $this->request('read_write', 'accountant'));

        self::assertFalse($result['isError']);
    }

    public function testSupplierHeaderIsForwardedToInternalCalls(): void
    {
        $this->api->on('GET /api/clients', [200, ['data' => [], 'meta' => []]]);

        $this->call('list_clients', [], $this->request('read', 'admin', ['X-Supplier-Id' => '4']));

        self::assertSame('4', $this->api->calls[0]['headers']['X-Supplier-Id']);
    }
}
