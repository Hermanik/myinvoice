<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Imap;

/**
 * Runs `fake_imap_server.php` as a child process listening on a loopback port.
 */
final class FakeImapServer
{
    /** @var resource */
    private $process;
    private readonly string $file;
    public readonly int $port;

    /**
     * @param array<int,string> $rawMessagesByUid Raw RFC 5322 messages keyed by IMAP UID.
     */
    public function __construct(array $rawMessagesByUid)
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'fakeimap');
        file_put_contents($this->file, json_encode(array_map('base64_encode', $rawMessagesByUid), JSON_THROW_ON_ERROR));

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fake_imap_server.php', $this->file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the fake IMAP server.');
        }
        $this->process = $process;
        $port = (int) trim((string) fgets($pipes[1]));
        if ($port <= 0) {
            throw new \RuntimeException('Fake IMAP server did not report a port: ' . stream_get_contents($pipes[2]));
        }
        $this->port = $port;
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->file);
    }
}
