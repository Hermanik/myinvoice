<?php

declare(strict_types=1);

/**
 * RESET — vymaže všechna uživatelská data ze systému (ponechá schéma + globální číselníky).
 *
 *   php api/bin/reset.php             # interaktivní potvrzení
 *   php api/bin/reset.php --yes       # bez ptaní
 *   php api/bin/reset.php --yes --keep-cache   # ponechá ARES/VIES cache
 *   php api/bin/reset.php --keep-users-supplier # ponechá účet(y) + dodavatele + jeho konfiguraci,
 *                                               # smaže jen byznys data (klienti, doklady, banka…)
 *
 * DYNAMICKÉ mazání: vymaže VŠECHNY tabulky kromě keep-listu (viz níže $keep),
 *       takže nezaostává za schématem — nové tabulky (vč. secretů: IMAP hesla,
 *       podpisové certifikáty) se po migraci automaticky vyčistí.
 * Ponechává (globální číselníky + schema): countries, vat_rates, units,
 *       tax_constants, exchange_rates (cache ČNB kurzů — drahé refetchnout), migrations.
 *       S --keep-cache navíc ares_cache/vies_cache/crpdph_cache.
 * Globální seed (supplier_id IS NULL) zůstává u: vat_classifications,
 *       bank_email_notice_providers — maže se jen per-tenant.
 * Vše ostatní (users, supplier, currencies, doklady, banka, dokumenty, podpisy,
 *       importy, cache přepočtů, …) se TRUNCATE.
 *
 * Pozn.: currencies jsou per-supplier (multi-tenant), takže s ním padají.
 * Po resetu setup.php založí novému supplier defaultní CZK + EUR.
 *
 * Po resetu spusť znovu:
 *   php api/bin/setup.php       # admin + supplier + currencies
 *   php api/bin/sample.php      # (volitelné) testovací data
 *
 * Pro úplný restart včetně schema: DROP DATABASE + CREATE DATABASE + migrate.php
 * (reset.php schema záměrně neshazuje).
 */

// === CLI guard — odmítni HTTP přístup ===
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Tento skript lze spustit pouze z příkazové řádky (CLI).\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Config\CfgLocalWriter;

$args = array_flip(array_slice($argv, 1));
$autoYes   = isset($args['--yes']) || isset($args['-y']);
$keepCache = isset($args['--keep-cache']);
$keepUsersSupplier = isset($args['--keep-users-supplier']);

$rootDir = Bootstrap::rootDir();

try {
    $config = Config::load($rootDir);
    $pdo    = (new Connection($config))->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "[reset] Chyba: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[reset] Pravděpodobně chybí cfg.php nebo DB. Spusť `php api/bin/setup.php`.\n");
    exit(1);
}

echo "================================================\n";
echo "  MyInvoice.cz — RESET DATA\n";
echo "================================================\n";
echo "  DB:   " . $config->get('db.name') . " @ " . $config->get('db.host') . "\n";
echo "  Root: $rootDir\n";
echo "================================================\n\n";

// Stats před resetem
$counts = [];
foreach (['users', 'invoices', 'clients', 'projects', 'bank_statements', 'activity_log'] as $t) {
    try {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    } catch (\Throwable) {
        $counts[$t] = '?';
    }
}
echo "Aktuální stav:\n";
foreach ($counts as $t => $c) printf("  %-20s %s\n", $t, $c);
echo "\n";

if ($keepUsersSupplier) {
    echo "Režim: --keep-users-supplier — ZACHOVÁ uživatele, dodavatele a jeho konfiguraci\n";
    echo "       (měny, číslování, podepisování, e-mail/banka config, číselníky).\n";
    echo "       Smaže BYZNYS data: klienti, doklady, banka, dokumenty, kniha jízd, recurring…\n\n";
}

if (!$autoYes) {
    echo $keepUsersSupplier
        ? "POZOR: smaže všechna byznys data (účet a firma zůstanou). Pokračovat? (napiš 'ANO'): "
        : "POZOR: smaže veškerá data v systému. Pokračovat? (napiš 'ANO'): ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'ANO') {
        echo "Zrušeno.\n";
        exit(0);
    }
}

// Reset je DYNAMICKÝ: vymaže VŠECHNY tabulky kromě keep-listu (globální číselníky +
// schema + drahé cache). Díky tomu nezaostává za schématem — nové tabulky se po
// migraci automaticky vyčistí (důležité např. pro IMAP účty / podpisové certifikáty
// se šifrovanými secrety). Pokud přibude nová GLOBÁLNÍ tabulka, přidej ji do $keep,
// jinak se taky smaže.
$keep = [
    'countries',      // globální číselník zemí
    'vat_rates',      // globální sazby DPH
    'units',          // globální měrné jednotky
    'tax_constants',  // globální daňové konstanty
    'exchange_rates', // cache kurzů ČNB — drahé refetchnout
    'migrations',     // evidence schématu
];
// ARES/VIES/CRPDPH cache — defaultně mažeme, s --keep-cache ponecháme.
if ($keepCache) {
    $keep = array_merge($keep, ['ares_cache', 'vies_cache', 'crpdph_cache']);
}

// Tabulky s globálním seedem (supplier_id IS NULL) — smaž jen per-tenant řádky.
$partial = [
    'vat_classifications'         => 'supplier_id IS NOT NULL',
    'bank_email_notice_providers' => 'supplier_id IS NOT NULL', // ponech globální bankovní providery
];

// --keep-users-supplier: zachovej účet(y) + dodavatele + jeho KONFIGURACI (měny, číslování,
// podepisování, e-mail/banka config, číselníky), smaž jen BYZNYS data (klienti, doklady,
// banka, dokumenty, kniha jízd, recurring, importy, daňová podání…). „Start fresh" se
// zachovaným přihlášením a firmou — netřeba znovu setup. Užitečné i pro úklid duplicitních
// sample dat, která vznikla bez evidence (issue #162).
if ($keepUsersSupplier) {
    $keep = array_merge($keep, [
        // Účet a přihlášení
        'users', 'sessions', 'trusted_devices', 'login_otps',
        // Identita dodavatele + měny + číslování dokladů
        'supplier', 'currencies', 'invoice_counters', 'purchase_invoice_counters', 'app_meta',
        // Dated VAT status history of the kept supplier(s) — without it the status falls back to the cached flags
        'supplier_vat_status_history',
        // API tokeny (PAT)
        'api_tokens',
        // Podepisování PDF (konfigurace + klíče)
        'signing_profiles', 'signing_credentials', 'signing_settings',
        'signature_role_profiles', 'signature_user_profiles', 'signature_document_overrides',
        'pdf_signature_output_settings',
        // E-mail / bankovní avíza (konfigurace, NE zpracované zprávy)
        'bank_email_imap_settings', 'bank_email_account_mappings', 'email_templates',
        // Per-supplier číselníky
        'expense_categories', 'revenue_categories', 'tax_profiles', 'trip_categories',
    ]);
    // vat_classifications + bank_email_notice_providers jsou konfigurace → ponech CELÉ.
    unset($partial['vat_classifications'], $partial['bank_email_notice_providers']);
    $keep[] = 'vat_classifications';
    $keep[] = 'bank_email_notice_providers';
}

$allTables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

echo "\n[reset] Mažu tabulky…\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$total = 0;
foreach ($allTables as $t) {
    if (in_array($t, $keep, true)) {
        continue;
    }
    if (isset($partial[$t])) {
        try {
            $deleted = $pdo->exec("DELETE FROM `$t` WHERE {$partial[$t]}");
            echo "  ✓ $t (ponechán globální seed, smazáno {$deleted} tenant řádků)\n";
            $total++;
        } catch (\PDOException $e) {
            echo "  - $t (skipped: " . $e->getMessage() . ")\n";
        }
        continue;
    }
    try {
        $pdo->exec("TRUNCATE TABLE `$t`");
        echo "  ✓ $t\n";
        $total++;
    } catch (\PDOException $e) {
        // Fallback DELETE — TRUNCATE může v některých případech selhat i s FK_CHECKS=0.
        try {
            $pdo->exec("DELETE FROM `$t`");
            echo "  ✓ $t (DELETE)\n";
            $total++;
        } catch (\PDOException $e2) {
            echo "  - $t (skipped: " . $e2->getMessage() . ")\n";
        }
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// PDF cache + storage cleanup — vč. přijaté faktury archive + XSD (necháváme)
$dirs = [
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices'),
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices'),  // archive PDF dodavatelů (fáze 1)
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('documents'),          // sekce Dokumenty (soubory, náhledy, joby)
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf'),
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/twig'),
];
echo "\n[reset] Čistím cache adresáře…\n";
foreach ($dirs as $d) {
    if (is_dir($d)) {
        $count = wipeDir($d);
        echo "  ✓ $d ($count souborů)\n";
    }
}

// Zruš setup-time MFA přepínače v cfg.local.php (jinak by stará hodnota přežila nový setup).
// S --keep-users-supplier účet zůstává → NEsahej na auth policy (nesnižuj bezpečnost).
if (!$keepUsersSupplier) {
    try {
        CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir($rootDir), [
            'auth.require_mfa' => null,
            'auth.allowed_mfa_methods' => ['passkey', 'totp'],
            'auth.require_totp' => false,
        ]);
        echo "\n[reset] cfg.local.php: MFA politika vrácena na výchozí hodnoty\n";
    } catch (\Throwable $e) {
        echo "\n[reset] cfg.local.php: nelze zapsat (" . $e->getMessage() . ") — uprav ručně, pokud potřebuješ.\n";
    }
}

echo "\n================================================\n";
echo "  HOTOVO. Vymazáno $total tabulek.\n";
echo $keepUsersSupplier
    ? "  Účet a dodavatel zůstaly zachované — můžeš rovnou zadávat reálná data.\n"
    : "  Spusť `php api/bin/setup.php` pro nové úvodní nastavení.\n";
echo "================================================\n";

function wipeDir(string $dir): int
{
    $count = 0;
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            if (@unlink($f->getPathname())) $count++;
        }
    }
    return $count;
}
