<?php

namespace SyncBridge\Database;

/**
 * SyncRunRepository
 * Tracks every sync run and its structured log entries in the DB.
 */
class SyncRunRepository
{
    public function startRun(string $runId, string $command): int
    {
        return Database::insert('sync_runs', [
            'run_id'      => $runId,
            'command'     => $command,
            'status'      => 'running',
            'started_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateProgress(string $runId, array $data): void
    {
        $allowed = ['total_items','done_items','pushed','skipped','failed',
                    'current_product_id','current_phase','last_message'];
        $set = [];
        $params = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $set[]    = "`{$key}` = ?";
                $params[] = $data[$key];
            }
        }
        if (empty($set)) return;
        $params[] = $runId;
        Database::execute(
            "UPDATE sync_runs SET " . implode(', ', $set) . " WHERE run_id = ?",
            $params
        );
    }

    public function finishRun(string $runId, bool $ok, float $elapsed): void
    {
        Database::execute(
            "UPDATE sync_runs SET status=?, finished_at=NOW(), elapsed_sec=? WHERE run_id=?",
            [$ok ? 'completed' : 'failed', round($elapsed, 2), $runId]
        );
    }

    public function getLastSuccessfulStartedAt(string $command): ?string
    {
        return Database::fetchValue(
            "SELECT DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s')
             FROM sync_runs
             WHERE command = ? AND status = 'completed'
             ORDER BY started_at DESC
             LIMIT 1",
            [$command]
        );
    }

    public function getCurrent(): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM sync_runs WHERE status='running' ORDER BY started_at DESC LIMIT 1"
        );
    }

    public function getRecent(int $limit = 20): array
    {
        return Database::fetchAll(
            "SELECT * FROM sync_runs ORDER BY started_at DESC LIMIT " . (int) $limit
        );
    }

    // ----------------------------------------------------------------
    // LOGS
    // ----------------------------------------------------------------

    public function log(string $runId, string $level, string $channel, string $message, array $context = []): void
    {
        Database::insert('sync_logs', [
            'run_id'    => $runId,
            'level'     => $level,
            'channel'   => $channel,
            'message'   => $message,
            'context'   => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'created_at'=> date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000),
        ]);
    }

    public function logBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        Database::beginTransaction();
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $this->log(
                    (string) ($row['run_id'] ?? ''),
                    (string) ($row['level'] ?? 'info'),
                    (string) ($row['channel'] ?? 'sync'),
                    (string) ($row['message'] ?? ''),
                    (array) ($row['context'] ?? [])
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    public function getLogs(array $filters = [], int $limit = 200): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['run_id'])) {
            $where[]  = 'run_id = ?';
            $params[] = $filters['run_id'];
        }
        if (!empty($filters['level'])) {
            $where[]  = 'level = ?';
            $params[] = $filters['level'];
        }
        if (!empty($filters['channel'])) {
            $where[]  = 'channel = ?';
            $params[] = $filters['channel'];
        }
        if (!empty($filters['search'])) {
            $where[]  = 'message LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        return Database::fetchAll(
            "SELECT * FROM sync_logs WHERE {$whereStr} ORDER BY created_at DESC LIMIT " . (int) $limit,
            $params
        );
    }

    public function getLogsPage(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['run_id'])) {
            $where[]  = 'run_id = ?';
            $params[] = $filters['run_id'];
        }
        if (!empty($filters['level'])) {
            $where[]  = 'level = ?';
            $params[] = $filters['level'];
        }
        if (!empty($filters['channel'])) {
            $where[]  = 'channel = ?';
            $params[] = $filters['channel'];
        }
        if (!empty($filters['search'])) {
            $where[]  = 'message LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;
        $whereStr = implode(' AND ', $where);

        $total = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM sync_logs WHERE {$whereStr}",
            $params
        );
        $rows = Database::fetchAll(
            "SELECT * FROM sync_logs WHERE {$whereStr} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }
}
