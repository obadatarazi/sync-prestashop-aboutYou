<?php

namespace SyncBridge\Database;

final class RetryJobRepository
{
    public function enqueue(string $jobType, string $entityKey, array $payload, string $reason, int $attempts = 1): void
    {
        Database::execute(
            "INSERT INTO retry_jobs (job_type, entity_key, payload_json, last_error, attempts, next_retry_at, status)
             VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
             ON DUPLICATE KEY UPDATE
               payload_json=VALUES(payload_json),
               last_error=VALUES(last_error),
               attempts=attempts + VALUES(attempts),
               next_retry_at=NOW(),
               status='pending',
               updated_at=NOW()",
            [
                $jobType,
                $entityKey,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $reason,
                $attempts,
            ]
        );
    }

    public function markDone(string $jobType, string $entityKey): void
    {
        Database::execute(
            "UPDATE retry_jobs SET status='done', updated_at=NOW() WHERE job_type=? AND entity_key=?",
            [$jobType, $entityKey]
        );
    }

    public function listPending(int $limit = 100): array
    {
        return Database::fetchAll(
            "SELECT * FROM retry_jobs
             WHERE status='pending' AND next_retry_at <= NOW()
             ORDER BY updated_at ASC
             LIMIT ?",
            [$limit]
        );
    }
}
