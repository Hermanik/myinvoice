<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — databox-check
 *
 * Read-only connectivity check of one connected data box, meant to be run once after the credentials were
 * entered (or against a real box for verification). It performs:
 *
 *   1. GetOwnerInfoFromLogin2 + GetUserInfoFromLogin2 + GetPasswordInfo   who the login is, which privileges it has
 *   2. an envelope list: the list of RECEIVED messages when the login cannot read (safe), otherwise only the list of
 *      SENT messages. Listing received messages with a user who may read them DELIVERS the messages legally and
 *      starts deadlines, so it is skipped unless you pass --allow-delivery.
 *
 * It never downloads message content, sends, writes to ISDS or changes the database.
 *
 * Usage:
 *   php api/bin/databox-check.php --box=<databox id> [--purpose=sync|action] [--days=30] [--allow-delivery] [--json]
 *
 * Exit codes: 0 = OK, 1 = ISDS / login problem, 2 = usage error.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\DataboxPrivileges;
use MyInvoice\Service\Databox\DataboxService;
use MyInvoice\Service\Databox\IsdsClient;
use MyInvoice\Service\Databox\IsdsEnvironment;
use MyInvoice\Service\Databox\IsdsException;

$opts = getopt('', ['box:', 'purpose::', 'days::', 'allow-delivery', 'json', 'help']);
if (isset($opts['help']) || !isset($opts['box'])) {
    fwrite(STDERR, "Usage: php api/bin/databox-check.php --box=<databox id> [--purpose=sync|action] [--days=30] [--allow-delivery] [--json]\n");
    exit(isset($opts['help']) ? 0 : 2);
}
$boxId = (int) $opts['box'];
$purpose = (string) ($opts['purpose'] ?? 'sync');
$days = max(1, min(100, (int) ($opts['days'] ?? 30)));
$allowDelivery = isset($opts['allow-delivery']);
$asJson = isset($opts['json']);
if (!in_array($purpose, DataboxService::PURPOSES, true)) {
    fwrite(STDERR, "--purpose must be sync or action.\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$box = $container->get(DataboxRepository::class)->find($boxId);
if ($box === null) {
    fwrite(STDERR, "Data box #{$boxId} does not exist.\n");
    exit(2);
}

$service = $container->get(DataboxService::class);
$isds = $container->get(IsdsClient::class);
$out = [
    'box' => ['id' => $box['id'], 'label' => $box['label'], 'db_id' => $box['db_id'], 'db_type' => $box['db_type'], 'environment' => $box['environment']],
    'purpose' => $purpose,
    'endpoint' => IsdsEnvironment::endpoint($box['environment'], IsdsEnvironment::SERVICE_INFO),
];

try {
    $access = $service->accessFor($box, $purpose);
    $probe = $service->probe($access);
    $privileges = $probe['privileges'];
    $out['login'] = [
        'username' => $access->credentials->username,
        'box_id_from_login' => $probe['owner']['db_id'],
        'box_type_from_login' => $probe['owner']['db_type'],
        'owner_name' => $probe['owner']['name'],
        'user_type' => $probe['user']['user_type'],
        'privileges' => $privileges->mask,
        'privilege_labels' => $privileges->labels(),
        'sync_safety' => $privileges->syncSafety(),
        'password_expires_at' => $probe['password_expires_at']?->format('c'),
        'matches_stored_box' => strtolower((string) $probe['owner']['db_id']) === $box['db_id'],
    ];

    $to = new DateTimeImmutable('+1 day');
    $from = new DateTimeImmutable("-{$days} days");
    if (!$privileges->canList()) {
        $out['list'] = ['skipped' => 'the login has no right to list messages (Zobrazovat seznamy, historii a doručenky)'];
    } elseif ($privileges->deliversOnList() && !$allowDelivery) {
        // Never deliver by accident: show the SENT list instead, which cannot deliver anything.
        $out['list'] = ['skipped' => 'received list skipped: this login can read messages, so listing them would deliver them (use --allow-delivery to override)'];
        $out['sent'] = array_map('summarize', $isds->listSent($access, $from, $to));
    } else {
        $out['received'] = array_map('summarize', $isds->listReceived($access, $from, $to));
        $out['sent'] = array_map('summarize', $isds->listSent($access, $from, $to));
    }
} catch (DataboxException | IsdsException $e) {
    $out['error'] = ['reason' => $e instanceof IsdsException ? $e->reason : $e->errorCode, 'message' => $e->getMessage()];
}

/** @param array<string,mixed> $m */
function summarize(array $m): array
{
    return [
        'dm_id' => $m['dm_id'], 'status' => $m['status'], 'delivered_to_box' => $m['delivery_time']?->format('c'),
        'sender' => $m['sender_name'], 'recipient' => $m['recipient_name'], 'annotation' => $m['annotation'],
    ];
}

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo "Data box #{$out['box']['id']} {$out['box']['label']} ({$out['box']['db_id']}, {$out['box']['db_type']}, {$out['box']['environment']})\n";
    echo "Endpoint: {$out['endpoint']}   login purpose: {$purpose}\n";
    if (isset($out['login'])) {
        $l = $out['login'];
        echo "Login:    {$l['username']}  type={$l['user_type']}  box={$l['box_id_from_login']} ({$l['box_type_from_login']})"
            . ($l['matches_stored_box'] ? '' : '  !! DIFFERENT FROM THE STORED BOX') . "\n";
        echo "Rights:   " . ($l['privilege_labels'] !== [] ? implode('; ', $l['privilege_labels']) : '(none)') . "  [mask {$l['privileges']}]\n";
        echo "Sync:     {$l['sync_safety']}" . ($l['sync_safety'] === 'safe' ? '  (lists envelopes, cannot deliver)' : ($l['sync_safety'] === 'delivers' ? '  (listing DELIVERS messages)' : '')) . "\n";
        echo "Password: " . ($l['password_expires_at'] ?? 'does not expire') . "\n";
    }
    foreach (['list' => null, 'received' => 'Received', 'sent' => 'Sent'] as $key => $title) {
        if ($key === 'list' && isset($out['list']['skipped'])) {
            echo "List:     {$out['list']['skipped']}\n";
        } elseif ($title !== null && isset($out[$key])) {
            echo "{$title} (" . count($out[$key]) . " in the last {$days} days):\n";
            foreach ($out[$key] as $m) {
                echo sprintf("  #%s  state %s  %s  %s -> %s  %s\n", $m['dm_id'], $m['status'] ?? '?', $m['delivered_to_box'] ?? '-', $m['sender'] ?? '-', $m['recipient'] ?? '-', $m['annotation'] ?? '');
            }
        }
    }
    if (isset($out['error'])) {
        fwrite(STDERR, "ERROR [{$out['error']['reason']}]: {$out['error']['message']}\n");
    }
}
exit(isset($out['error']) ? 1 : 0);
