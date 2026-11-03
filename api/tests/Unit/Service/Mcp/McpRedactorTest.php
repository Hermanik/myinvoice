<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\McpRedactor;
use PHPUnit\Framework\TestCase;

final class McpRedactorTest extends TestCase
{
    public function testSecretLookingKeysAreRemovedRecursively(): void
    {
        $clean = McpRedactor::redact([
            'id' => 1,
            'public_token' => 'x', 'approval_token' => 'x', 'password_hash' => 'x', 'smtp_password' => 'x',
            'imap_pass_enc' => 'x', 'api_key' => 'x', 'apiKey' => 'x', 'private_key' => 'x', 'passphrase' => 'x',
            'client_secret' => 'x', 'credentials' => ['a' => 1], 'totp_secret' => 'x',
            'nested' => ['ok' => true, 'access_token' => 'x', 'list' => [['name' => 'n', 'secret' => 'x']]],
        ]);

        self::assertSame(['id' => 1, 'nested' => ['ok' => true, 'list' => [['name' => 'n']]]], $clean);
    }

    public function testOrdinaryBusinessKeysSurvive(): void
    {
        $data = [
            'varsymbol' => '1', 'number' => '1', 'tax_number' => '1', 'ic' => '1', 'iban' => 'CZ00', 'bank_reference' => 'r',
            'unit_price_without_vat' => 1, 'vat_classification_code' => 'x', 'note' => 'n',
        ];

        self::assertSame($data, McpRedactor::redact($data));
    }

    public function testValuesAreNotInspected(): void
    {
        $data = ['note' => 'the password is hidden in a token'];

        self::assertSame($data, McpRedactor::redact($data));
    }
}
