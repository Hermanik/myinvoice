<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Staged outgoing message between "prepare" and the user's explicit confirmation (tables databox_outbox,
 * databox_outbox_files). A staged payload is consumed exactly once.
 */
final class DataboxOutboxRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $summary
     * @param list<array{name:string, mime:string, bytes:string}> $files
     */
    public function stage(string $token, int $supplierId, int $boxId, int $userId, ?int $submissionId, array $envelope, array $summary, array $files, \DateTimeImmutable $now, \DateTimeImmutable $expires): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO databox_outbox (token, supplier_id, databox_id, user_id, submission_id, envelope_json, summary_json, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $token, $supplierId, $boxId, $userId, $submissionId,
                json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $now->format('Y-m-d H:i:s'), $expires->format('Y-m-d H:i:s'),
            ]);
            $insert = $pdo->prepare('INSERT INTO databox_outbox_files (token, position, name, mime, content, size_bytes, sha256) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($files as $i => $file) {
                $insert->bindValue(1, $token);
                $insert->bindValue(2, $i, \PDO::PARAM_INT);
                $insert->bindValue(3, $file['name']);
                $insert->bindValue(4, $file['mime']);
                $insert->bindValue(5, $file['bytes'], \PDO::PARAM_LOB);
                $insert->bindValue(6, strlen($file['bytes']), \PDO::PARAM_INT);
                $insert->bindValue(7, hash('sha256', $file['bytes']));
                $insert->execute();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The staged payload WITHOUT its attachments (they can be 20 MB) - enough to check ownership and the summary.
     *
     * @return array{supplier_id:int, databox_id:int, user_id:int, submission_id:?int, envelope:array<string,mixed>, summary:array<string,mixed>}|null
     */
    public function peek(string $token, \DateTimeImmutable $now): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM databox_outbox WHERE token = ? AND expires_at >= ?');
        $stmt->execute([$token, $now->format('Y-m-d H:i:s')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->header($row);
    }

    /**
     * Take the staged payload including its attachments and delete it in the same transaction, so two parallel
     * confirmations cannot both send it.
     *
     * @return array{supplier_id:int, databox_id:int, user_id:int, submission_id:?int, envelope:array<string,mixed>, summary:array<string,mixed>, files:list<array{name:string, mime:string, bytes:string}>}|null
     */
    public function consume(string $token, \DateTimeImmutable $now): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM databox_outbox WHERE token = ? FOR UPDATE');
            $stmt->execute([$token]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || new \DateTimeImmutable((string) $row['expires_at']) < $now) {
                $pdo->rollBack();
                return null;
            }
            $filesStmt = $pdo->prepare('SELECT name, mime, content FROM databox_outbox_files WHERE token = ? ORDER BY position');
            $filesStmt->execute([$token]);
            $files = array_map(
                static fn (array $f): array => ['name' => (string) $f['name'], 'mime' => (string) $f['mime'], 'bytes' => (string) $f['content']],
                $filesStmt->fetchAll(\PDO::FETCH_ASSOC),
            );
            $pdo->prepare('DELETE FROM databox_outbox WHERE token = ?')->execute([$token]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->header($row) + ['files' => $files];
    }

    /** @param array<string,mixed> $row @return array{supplier_id:int, databox_id:int, user_id:int, submission_id:?int, envelope:array<string,mixed>, summary:array<string,mixed>} */
    private function header(array $row): array
    {
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'databox_id' => (int) $row['databox_id'],
            'user_id' => (int) $row['user_id'],
            'submission_id' => $row['submission_id'] !== null ? (int) $row['submission_id'] : null,
            'envelope' => json_decode((string) $row['envelope_json'], true) ?: [],
            'summary' => json_decode((string) $row['summary_json'], true) ?: [],
        ];
    }

    public function purgeExpired(\DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare('DELETE FROM databox_outbox WHERE expires_at < ?')->execute([$now->format('Y-m-d H:i:s')]);
    }
}
