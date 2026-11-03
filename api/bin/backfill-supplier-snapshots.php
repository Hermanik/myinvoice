<?php

declare(strict_types=1);

/**
 * Backfill supplier_snapshot pro faktury, které mají jen stub
 * (např. `{"company_name":"..."}` z dřívější migrace dat z jiného systému).
 *
 * Použití:
 *   php api/bin/backfill-supplier-snapshots.php           # dry-run, vypíše kolik faktur opraví
 *   php api/bin/backfill-supplier-snapshots.php --apply   # zapíše do DB
 *
 * Logika: rebuild snapshot z aktuální tabulky `supplier`. Spouští se pouze pro invoices,
 * kde stávající snapshot postrádá `street` nebo `is_vat_payer` (= klíčové fingerprint
 * stub-snapshot-u). Faktury s plným snapshotem se nedotýká.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$apply = in_array('--apply', $argv, true);

$config = Config::load(Bootstrap::rootDir());
$conn = new Connection($config);
$pdo = $conn->pdo();
$vatStatus = \MyInvoice\Service\Vat\VatStatusService::forConnection($conn);

$rows = $pdo->query(
    "SELECT id, supplier_id, varsymbol, tax_date, issue_date
       FROM invoices
      WHERE supplier_snapshot IS NOT NULL
        AND (
            JSON_EXTRACT(supplier_snapshot, '$.street') IS NULL
         OR JSON_EXTRACT(supplier_snapshot, '$.is_vat_payer') IS NULL
        )"
)->fetchAll(\PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nic k opravě, všechny snapshoty jsou kompletní.\n";
    exit(0);
}

echo 'Faktur ke zpracování: ' . count($rows) . "\n";

if (!$apply) {
    echo "(dry-run — pro zápis spusť s --apply)\n";
    foreach (array_slice($rows, 0, 5) as $r) {
        echo "  #{$r['id']} VS={$r['varsymbol']} sid={$r['supplier_id']}\n";
    }
    if (count($rows) > 5) echo "  … a další " . (count($rows) - 5) . "\n";
    exit(0);
}

// Cache načtených supplierů — typicky jen pár id (1-3)
$supplierStmt = $pdo->prepare(
    'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
       FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
);
$cache = [];
$loadSupplier = function (int $sid) use (&$cache, $supplierStmt): ?array {
    if (!array_key_exists($sid, $cache)) {
        $supplierStmt->execute([$sid]);
        $row = $supplierStmt->fetch(\PDO::FETCH_ASSOC);
        $cache[$sid] = $row ?: null;
    }
    return $cache[$sid];
};

$update = $pdo->prepare('UPDATE invoices SET supplier_snapshot = ? WHERE id = ?');
$ok = 0;
$err = 0;
foreach ($rows as $r) {
    $sid = (int) $r['supplier_id'];
    $row = $loadSupplier($sid);
    if (!$row) {
        $err++;
        echo "  ✗ #{$r['id']}: supplier #$sid nenalezen\n";
        continue;
    }
    $snap = [
        'company_name'    => $row['company_name'],
        'display_name'    => $row['display_name'],
        'street'          => $row['street'],
        'city'            => $row['city'],
        'zip'             => $row['zip'],
        'country_iso2'    => $row['country_iso2'],
        'country_name_cs' => $row['country_name_cs'],
        'country_name_en' => $row['country_name_en'],
        'ic'              => $row['ic'],
        'dic'             => $row['dic'],
        // VAT status at the invoice's DUZP (dated history), not the live flag.
        ...$vatStatus->flagsForDocument($sid, $r),
        'email'           => $row['email'],
        'phone'           => $row['phone'],
        'web'             => $row['web'],
        'tagline'         => $row['tagline'] ?? null,
    ];
    try {
        $update->execute([
            json_encode($snap, JSON_UNESCAPED_UNICODE),
            (int) $r['id'],
        ]);
        $ok++;
    } catch (\Throwable $e) {
        $err++;
        echo "  ✗ #{$r['id']}: " . $e->getMessage() . "\n";
    }
}

echo "Hotovo: $ok opraveno" . ($err > 0 ? ", $err chyb" : '') . ".\n";
