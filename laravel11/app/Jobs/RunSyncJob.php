<?php

namespace App\Jobs;

use App\Services\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunSyncJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $command, private readonly array $options = [])
    {
    }

    public function handle(SyncRunner $runner): void
    {
        $runner->run($this->command, $this->options);
    }
}
