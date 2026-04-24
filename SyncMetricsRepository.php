<?php

namespace SyncBridge\Database;

final class SyncMetricsRepository
{
    public function recordRunMetric(
        string $runId,
        string $command,
        string $phase,
        string $metricKey,
        float|int $metricValue,
        array $meta = []
    ): void {
        Database::execute(
            "INSERT INTO sync_metrics (run_id, command, phase, metric_key, metric_value, meta_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $runId,
                $command,
                $phase,
                $metricKey,
                (float) $metricValue,
                $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    public function recentMetrics(int $limit = 200): array
    {
        return Database::fetchAll(
            "SELECT * FROM sync_metrics ORDER BY created_at DESC LIMIT " . max(1, min(1000, $limit))
        );
    }
}
