<?php

namespace App\Console\Commands;

use App\Services\Integration\AyCategorySyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncAyCategoriesCommand extends Command
{
    protected $signature = 'ay:sync-categories';

    protected $description = 'Fetch the full About You category tree from the API and store it in ay_categories (upsert + prune missing).';

    public function handle(): int
    {
        if (trim((string) env('AY_API_KEY', '')) === '') {
            $this->error('About You is not configured (set AY_API_KEY in .env).');

            return self::FAILURE;
        }

        try {
            /** @var AyCategorySyncService $sync */
            $sync = app(AyCategorySyncService::class);
            $this->info('Syncing About You categories (this may take several minutes)…');
            $result = $sync->syncFullTree();
            $this->info(sprintf('Done. Upserted %d rows, pruned %d obsolete rows.', $result['discovered'], $result['pruned']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
