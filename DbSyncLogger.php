<?php

namespace SyncBridge\Services;

use SyncBridge\Database\SyncRunRepository;

/**
 * DbSyncLogger
 * Writes every log entry to:
 *   1) MySQL sync_logs table (queryable, filterable via UI)
 *   2) Rotating file via Monolog (existing behaviour preserved)
 */
class DbSyncLogger
{
    private SyncRunRepository $repo;
    private string $runId;
    private string $channel;
    private array $errorBuffer = [];
    private array $pendingRows = [];
    private int $lastFlushMs = 0;

    public function __construct(string $runId, string $channel = 'sync')
    {
        $this->repo    = new SyncRunRepository();
        $this->runId   = $runId;
        $this->channel = $channel;
        $this->lastFlushMs = (int) floor(microtime(true) * 1000);
    }

    public function debug(string $msg, array $ctx = []): void   { $this->write('debug',   $msg, $ctx); }
    public function info(string $msg, array $ctx = []): void    { $this->write('info',    $msg, $ctx); }
    public function notice(string $msg, array $ctx = []): void  { $this->write('notice',  $msg, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->write('warning', $msg, $ctx); }

    public function error(string $msg, array $ctx = []): void
    {
        $this->write('error', $msg, $ctx);
        $this->errorBuffer[] = ['message' => $msg, 'context' => $ctx, 'time' => date('c')];
        $this->triggerSlack($msg, $ctx);
    }

    public function critical(string $msg, array $ctx = []): void
    {
        $this->write('critical', $msg, $ctx);
        $this->triggerSlack($msg, $ctx, true);
    }

    private function write(string $level, string $msg, array $ctx): void
    {
        // DB log
        try {
            if (in_array($level, ['warning', 'error', 'critical'], true)) {
                $this->flushPending();
                $this->repo->log($this->runId, $level, $this->channel, $msg, $ctx);
            } else {
                $this->pendingRows[] = [
                    'run_id' => $this->runId,
                    'level' => $level,
                    'channel' => $this->channel,
                    'message' => $msg,
                    'context' => $ctx,
                ];
                $this->flushPending(false);
            }
        } catch (\Throwable) {
            // DB unavailable — fall through to file only
        }

        // Console + file
        $ts      = date('Y-m-d H:i:s');
        $ctxStr  = $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : '';
        $line    = "[{$ts}] {$this->channel}." . strtoupper($level) . ": {$msg}{$ctxStr}" . PHP_EOL;
        echo $line;
        $logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../../logs/sync.log';
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }

    public function __destruct()
    {
        $this->flushPending(true);
    }

    private function triggerSlack(string $msg, array $ctx, bool $critical = false): void
    {
        $webhook = $_ENV['NOTIFY_SLACK_WEBHOOK'] ?? '';
        if (!filter_var($_ENV['NOTIFY_SLACK_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN) || !$webhook) {
            return;
        }
        $emoji = $critical ? ':red_circle:' : ':warning:';
        $text  = "{$emoji} *Sync Error*\n`{$msg}`";
        if ($ctx) $text .= "\n```" . json_encode($ctx, JSON_PRETTY_PRINT) . "```";
        try {
            $ch = curl_init($webhook);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['text' => $text]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {}
    }

    public function getErrorBuffer(): array { return $this->errorBuffer; }

    private function flushPending(bool $force = false): void
    {
        if ($this->pendingRows === []) {
            return;
        }
        $now = (int) floor(microtime(true) * 1000);
        $batchSize = max(10, (int) ($_ENV['SYNC_DB_LOG_BATCH_SIZE'] ?? 25));
        $maxWaitMs = max(200, (int) ($_ENV['SYNC_DB_LOG_FLUSH_MS'] ?? 1000));
        if (!$force && count($this->pendingRows) < $batchSize && ($now - $this->lastFlushMs) < $maxWaitMs) {
            return;
        }
        try {
            $this->repo->logBatch($this->pendingRows);
            $this->pendingRows = [];
            $this->lastFlushMs = $now;
        } catch (\Throwable) {
        }
    }
}
