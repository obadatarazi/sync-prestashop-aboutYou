<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncRunner;
use Illuminate\Console\Command;

class RunSyncCommand extends Command
{
    protected $signature = 'syncbridge:run {sync_command=status} {--since=} {--ps_ids=}';
    protected $description = 'Run SyncBridge sync commands';

    public function handle(SyncRunner $runner): int
    {
        $payload = [
            'since' => $this->option('since'),
        ];

        $psIds = (string) $this->option('ps_ids');
        if ($psIds !== '') {
            $payload['ps_product_ids'] = array_values(array_filter(array_map('intval', explode(',', $psIds))));
        }

        $result = $runner->run((string) $this->argument('sync_command'), $payload);
        if (!($result['ok'] ?? false)) {
            $this->error((string) ($result['error'] ?? 'Sync failed'));
            return self::FAILURE;
        }

        $this->info('Run ID: ' . ($result['run_id'] ?? 'n/a'));
        if (!empty($result['dry_run'])) {
            $this->warn('DRY_RUN is enabled — no sync_runs row, no DB mutations, no mutating API calls.');
        }
        $this->line(json_encode($result['result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
