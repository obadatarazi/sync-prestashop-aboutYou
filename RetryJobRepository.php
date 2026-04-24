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

    public function scheduleRetry(string $jobType, string $entityKey, string $reason, int $attempts): void
    {
        $backoffSec = min(3600, (int) (pow(2, max(1, $attempts)) * 15));
        Database::execute(
            "UPDATE retry_jobs
             SET status='pending',
                 attempts=?,
                 last_error=?,
                 next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
                 updated_at=NOW()
             WHERE job_type=? AND entity_key=?",
            [$attempts, $reason, $backoffSec, $jobType, $entityKey]
        );
    }

    public function markDead(string $jobType, string $entityKey, string $reason): void
    {
        Database::execute(
            "UPDATE retry_jobs
             SET status='dead', last_error=?, updated_at=NOW()
             WHERE job_type=? AND entity_key=?",
            [$reason, $jobType, $entityKey]
        );
    }
}
