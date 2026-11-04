<?php

declare(strict_types=1);

/**
 * Minimal single-connection IMAP server on 127.0.0.1 used by tests to prove that messages come out
 * of the real Webklex client byte for byte. It serves the raw RFC 5322 messages from the JSON file
 * given as argv[1] (base64 strings keyed by UID) and prints the listening port on stdout.
 *
 * Usage: php fake_imap_server.php <messages.json>
 */

$messages = [];
foreach (json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR) as $uid => $b64) {
    $messages[(int) $uid] = (string) base64_decode($b64, true);
}
ksort($messages);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
echo explode(':', (string) stream_socket_get_name($server, false))[1], "\n";
fflush(STDOUT);

$conn = @stream_socket_accept($server, 20);
if ($conn === false) {
    exit(2);
}
stream_set_timeout($conn, 20);

$send = static function (string $data) use ($conn): void {
    fwrite($conn, $data);
};
$send("* OK IMAP4rev1 fake server ready\r\n");

$uids = array_keys($messages);
while (($line = fgets($conn)) !== false) {
    $line = rtrim($line, "\r\n");
    if (!preg_match('/^(\S+)\s+(.*)$/', $line, $m)) {
        continue;
    }
    [, $tag, $command] = $m;
    $upper = strtoupper($command);

    if (str_starts_with($upper, 'CAPABILITY')) {
        $send("* CAPABILITY IMAP4rev1\r\n$tag OK CAPABILITY completed\r\n");
    } elseif (str_starts_with($upper, 'LOGIN')) {
        $send("$tag OK LOGIN completed\r\n");
    } elseif (str_starts_with($upper, 'LOGOUT')) {
        $send("* BYE bye\r\n$tag OK LOGOUT completed\r\n");
        break;
    } elseif (str_starts_with($upper, 'LIST') || str_starts_with($upper, 'LSUB')) {
        $send("* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n$tag OK LIST completed\r\n");
    } elseif (str_starts_with($upper, 'SELECT') || str_starts_with($upper, 'EXAMINE')) {
        $send('* ' . count($messages) . " EXISTS\r\n* 0 RECENT\r\n* FLAGS (\\Seen \\Answered \\Flagged \\Deleted \\Draft)\r\n"
            . "* OK [UIDVALIDITY 1] ok\r\n* OK [UIDNEXT " . ((int) max($uids ?: [0]) + 1) . "] ok\r\n$tag OK [READ-WRITE] SELECT completed\r\n");
    } elseif (preg_match('/^(UID\s+)?SEARCH/i', $command) === 1) {
        $send('* SEARCH ' . implode(' ', $uids) . "\r\n$tag OK SEARCH completed\r\n");
    } elseif (preg_match('/^UID\s+FETCH\s+(\S+)\s+\((.*)\)\s*$/i', $command, $f) === 1) {
        $wanted = [];
        foreach (explode(',', $f[1]) as $part) {
            [$from, $to] = array_pad(explode(':', $part), 2, null);
            foreach ($uids as $uid) {
                if ($uid >= (int) $from && $uid <= (int) ($to === null ? $from : ($to === '*' ? PHP_INT_MAX : $to))) {
                    $wanted[$uid] = true;
                }
            }
        }
        $items = preg_split('/\s+/', trim($f[2])) ?: [];
        foreach (array_keys($wanted) as $uid) {
            $raw = $messages[$uid];
            $pos = strpos($raw, "\r\n\r\n");
            $header = $pos === false ? $raw : substr($raw, 0, $pos + 4);   // RFC822.HEADER includes the blank line
            $text = $pos === false ? '' : substr($raw, $pos + 4);
            $seq = array_search($uid, $uids, true) + 1;
            $out = "* $seq FETCH (";
            foreach ($items as $item) {
                $item = strtoupper($item);
                if ($item === 'FLAGS') {
                    $out .= 'FLAGS () ';
                } elseif ($item === 'RFC822.HEADER') {
                    $out .= 'RFC822.HEADER {' . strlen($header) . "}\r\n" . $header . ' ';
                } elseif ($item === 'RFC822.TEXT') {
                    $out .= 'RFC822.TEXT {' . strlen($text) . "}\r\n" . $text . ' ';
                }
            }
            $send($out . "UID $uid)\r\n");
        }
        $send("$tag OK FETCH completed\r\n");
    } else {
        // STORE, NOOP, anything else: accept.
        $send("$tag OK done\r\n");
    }
}
fclose($conn);
