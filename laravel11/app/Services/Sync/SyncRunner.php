<?php

namespace App\Services\Sync;

use App\Repositories\RetryJobRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\ProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SyncMetricsRepository;
use App\Services\Integration\AboutYouClient;
use App\Services\Integration\AboutYouMapper;
use App\Services\Integration\PrestaShopClient;
use App\Support\Database;
use App\Support\HttpClient;
use App\Support\ImageNormalizer;
use App\Support\ImageNormalizerFactory;
use App\Support\SyncFlags;

final class SyncRunner
{
    private SyncRunRepository $runs;
    private RetryJobRepository $retryJobs;
    private SyncMetricsRepository $metrics;

    public function __construct()
    {
        $this->runs = new SyncRunRepository();
        $this->retryJobs = new RetryJobRepository();
        $this->metrics = new SyncMetricsRepository();
    }

    public function run(string $command, array $options = []): array
    {
        $runId = bin2hex(random_bytes(8));

        if ($command === 'status') {
            return [
                'ok' => true,
                'run_id' => $runId,
                'result' => $this->buildStatusSnapshot(),
            ];
        }

        $dryRun = SyncFlags::dryRun();
        $started = microtime(true);
        if (!$dryRun) {
            $this->runs->startRun($runId, $command);
        }

        $http = new HttpClient(
            (int) ($_ENV['HTTP_TIMEOUT_SEC'] ?? 30),
            (int) ($_ENV['HTTP_MAX_RETRIES'] ?? 3)
        );
        $ps = new PrestaShopClient($http);
        $ay = new AboutYouClient($http);
        $mapper = new AboutYouMapper();
        $imageNormalizer = ImageNormalizerFactory::create($http);
        $productRepo = new ProductRepository();

        try {
            $psProductIds = array_values(array_filter(array_map('intval', (array) ($options['ps_product_ids'] ?? []))));
            $incrementalSince = $options['since']
                ?? $this->runs->getLastSuccessfulStartedAt('products:inc')
                ?? $this->runs->getLastSuccessfulStartedAt('products')
                ?? null;
            $hasSeededCatalog = ((int) (($productRepo->getStats()['total'] ?? 0))) > 0;
            $result = match ($command) {
                'products' => $psProductIds !== []
                    ? (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun))->syncForProductIds($psProductIds)
                    : (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun))->syncAll(),
                'products:inc' => $psProductIds !== []
                    ? (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun))->syncForProductIds($psProductIds)
                    : ((!$hasSeededCatalog && !isset($options['since']))
                        ? (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun))->syncAll()
                        : (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun))
                            ->syncIncremental($incrementalSince ?? date('Y-m-d H:i:s', strtotime('-1 day')))),
                'stock' => (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun))
                    ->syncStockAndPrices($options['since'] ?? $this->runs->getLastSuccessfulStartedAt('stock') ?? date('Y-m-d H:i:s', strtotime('-2 hours'))),
                'orders' => (new OrderSyncService($runId, $ps, $ay, $mapper, $dryRun))
                    ->importNewOrders($options['since'] ?? $this->runs->getLastSuccessfulStartedAt('orders') ?? date('Y-m-d H:i:s', strtotime('-1 day'))),
                'order-status' => (new OrderSyncService($runId, $ps, $ay, $mapper, $dryRun))
                    ->pushOrderStatusUpdates($options['since'] ?? null),
                'all' => $this->runAll($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun),
                'retry' => $this->runRetryQueue($options, $dryRun),
                default => throw new \InvalidArgumentException('Unsupported sync command: ' . $command),
            };

            $elapsed = microtime(true) - $started;
            $summary = $this->extractRunSummary(is_array($result) ? $result : []);
            if (!$dryRun) {
                $this->runs->updateProgress($runId, [
                    'current_phase' => 'complete',
                    'last_message' => sprintf(
                        'Completed in %.2fs (pushed=%d failed=%d skipped=%d)',
                        $elapsed,
                        $summary['pushed'],
                        $summary['failed'],
                        $summary['skipped']
                    ),
                    'pushed' => $summary['pushed'],
                    'failed' => $summary['failed'],
                    'skipped' => $summary['skipped'],
                ]);
                $this->runs->log($runId, 'info', 'metrics', 'sync_run_completed', [
                    'command' => $command,
                    'elapsed_sec' => round($elapsed, 3),
                    'summary' => $summary,
                    'dry_run' => $dryRun,
                ]);
                if (filter_var($_ENV['FEATURE_SYNC_METRICS'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                    $this->metrics->recordRunMetric($runId, $command, 'run', 'elapsed_sec', round($elapsed, 3), $summary);
                    $this->metrics->recordRunMetric($runId, $command, 'run', 'pushed', $summary['pushed']);
                    $this->metrics->recordRunMetric($runId, $command, 'run', 'failed', $summary['failed']);
                    $this->metrics->recordRunMetric($runId, $command, 'run', 'skipped', $summary['skipped']);
                }
                $this->runs->finishRun($runId, true, $elapsed);
            }

            return [
                'ok' => true,
                'run_id' => $runId,
                'dry_run' => $dryRun,
                'result' => is_array($result) ? (['dry_run' => $dryRun] + $result) : $result,
            ];
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $started;
            if (!$dryRun) {
                $this->runs->log($runId, 'error', 'metrics', 'sync_run_failed', [
                    'command' => $command,
                    'elapsed_sec' => round($elapsed, 3),
                ]);
                $this->runs->finishRun($runId, false, $elapsed);
                $this->runs->log($runId, 'critical', 'sync', $e->getMessage(), ['exception' => get_class($e)]);
            }

            return [
                'ok' => false,
                'run_id' => $runId,
                'dry_run' => $dryRun,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusSnapshot(): array
    {
        try {
            $products = (int) Database::fetchValue('SELECT COUNT(*) FROM products');
            $orders = (int) Database::fetchValue('SELECT COUNT(*) FROM orders');
            $syncRuns = (int) Database::fetchValue('SELECT COUNT(*) FROM sync_runs');
        } catch (\Throwable $e) {
            return [
                'database_error' => $e->getMessage(),
                'api' => [
                    'prestashop' => $_ENV['PS_BASE_URL'] ?? '(not set)',
                    'aboutyou' => $_ENV['AY_BASE_URL'] ?? '(not set)',
                ],
                'flags' => [
                    'dry_run' => SyncFlags::dryRun(),
                    'test_mode' => SyncFlags::testMode(),
                ],
            ];
        }

        return [
            'database' => [
                'products' => $products,
                'orders' => $orders,
                'sync_runs' => $syncRuns,
            ],
            'api' => [
                'prestashop' => $_ENV['PS_BASE_URL'] ?? '(not set)',
                'aboutyou' => $_ENV['AY_BASE_URL'] ?? '(not set)',
            ],
            'flags' => [
                'dry_run' => SyncFlags::dryRun(),
                'test_mode' => SyncFlags::testMode(),
            ],
            'message' => 'System ready',
        ];
    }

    private function runAll(
        string $runId,
        PrestaShopClient $ps,
        AboutYouClient $ay,
        AboutYouMapper $mapper,
        ?ImageNormalizer $imageNormalizer,
        bool $dryRun = false
    ): array {
        $productService = new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer, $dryRun);
        $orderService = new OrderSyncService($runId, $ps, $ay, $mapper, $dryRun);

        return [
            'products' => $productService->syncIncremental(
                $this->runs->getLastSuccessfulStartedAt('products:inc') ?? date('Y-m-d H:i:s', strtotime('-1 day'))
            ),
            'stock' => $productService->syncStockAndPrices(
                $this->runs->getLastSuccessfulStartedAt('stock') ?? date('Y-m-d H:i:s', strtotime('-2 hours'))
            ),
            'orders' => $orderService->importNewOrders(
                $this->runs->getLastSuccessfulStartedAt('orders') ?? date('Y-m-d H:i:s', strtotime('-1 day'))
            ),
            'order_status' => $orderService->pushOrderStatusUpdates(),
        ];
    }

    private function runRetryQueue(array $options, bool $dryRun = false): array
    {
        if ($dryRun) {
            return [
                'processed' => 0,
                'done' => 0,
                'rescheduled' => 0,
                'dead' => 0,
                'note' => 'DRY_RUN: retry queue not processed (would update DB and call APIs)',
            ];
        }

        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));
        $maxAttempts = max(1, (int) ($_ENV['RETRY_MAX_ATTEMPTS'] ?? 5));
        $jobs = $this->retryJobs->listPending($limit);
        $processed = 0;
        $done = 0;
        $rescheduled = 0;
        $dead = 0;

        foreach ($jobs as $job) {
            $processed++;
            $jobType = (string) ($job['job_type'] ?? '');
            $entityKey = (string) ($job['entity_key'] ?? '');
            $attempts = (int) ($job['attempts'] ?? 0) + 1;
            try {
                if ($jobType === 'product_push') {
                    $psId = (int) $entityKey;
                    $result = $this->run('products', ['ps_product_ids' => [$psId]]);
                    if (!($result['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($result['error'] ?? 'product retry failed'));
                    }
                    $nestedResult = is_array($result['result'] ?? null) ? $result['result'] : [];
                    $summary = $this->extractRunSummary($nestedResult);
                    if (($summary['failed'] ?? 0) > 0) {
                        throw new \RuntimeException(sprintf(
                            'product retry failed for PS#%d (failed=%d)',
                            $psId,
                            (int) $summary['failed']
                        ));
                    }
                } elseif ($jobType === 'order_status') {
                    $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
                    if (!is_array($payload)) {
                        $payload = [];
                    }
                    $status = trim((string) ($payload['ay_status'] ?? ''));
                    if ($status === '') {
                        throw new \RuntimeException('order_status retry missing ay_status payload');
                    }
                    // Reconstruct status extras (tracking/return keys) so retry mirrors the original push.
                    $extraRaw = $payload['extra'] ?? [];
                    $extra = is_array($extraRaw) ? $extraRaw : [];
                    foreach (['tracking_number', 'return_tracking_key'] as $legacyKey) {
                        if (!array_key_exists($legacyKey, $extra) && isset($payload[$legacyKey])) {
                            $extra[$legacyKey] = $payload[$legacyKey];
                        }
                    }
                    $http = new HttpClient(
                        (int) ($_ENV['HTTP_TIMEOUT_SEC'] ?? 30),
                        (int) ($_ENV['HTTP_MAX_RETRIES'] ?? 3)
                    );
                    $ay = new AboutYouClient($http);
                    if (!$ay->updateOrderStatus($entityKey, $status, $extra)) {
                        throw new \RuntimeException('order status retry failed');
                    }
                    (new OrderRepository())->markStatusPushed($entityKey, $status);
                } elseif ($jobType === 'order_import') {
                    $http = new HttpClient(
                        (int) ($_ENV['HTTP_TIMEOUT_SEC'] ?? 30),
                        (int) ($_ENV['HTTP_MAX_RETRIES'] ?? 3)
                    );
                    $ps = new PrestaShopClient($http);
                    $ay = new AboutYouClient($http);
                    $mapper = new AboutYouMapper();
                    $imported = (new OrderSyncService('retry-' . bin2hex(random_bytes(6)), $ps, $ay, $mapper))
                        ->retryImportByAyOrderId($entityKey);
                    if (!$imported) {
                        throw new \RuntimeException('order import retry failed');
                    }
                } else {
                    throw new \RuntimeException('Unsupported retry job type: ' . $jobType);
                }

                $this->retryJobs->markDone($jobType, $entityKey);
                $done++;
            } catch (\Throwable $e) {
                if ($attempts >= $maxAttempts) {
                    $this->retryJobs->markDead($jobType, $entityKey, $e->getMessage());
                    $dead++;
                } else {
                    $this->retryJobs->scheduleRetry($jobType, $entityKey, $e->getMessage(), $attempts);
                    $rescheduled++;
                }
            }
        }

        return [
            'processed' => $processed,
            'done' => $done,
            'rescheduled' => $rescheduled,
            'dead' => $dead,
        ];
    }

    private function extractRunSummary(array $result): array
    {
        $summary = ['pushed' => 0, 'failed' => 0, 'skipped' => 0];
        $stack = [$result];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }
                if (!is_numeric($value)) {
                    continue;
                }
                $intValue = (int) $value;
                if (in_array($key, ['pushed', 'orders_imported', 'statuses_pushed'], true)) {
                    $summary['pushed'] += $intValue;
                } elseif (in_array($key, ['failed', 'orders_failed', 'status_push_failed'], true)) {
                    $summary['failed'] += $intValue;
                } elseif (in_array($key, ['skipped', 'orders_skipped'], true)) {
                    $summary['skipped'] += $intValue;
                }
            }
        }

        return $summary;
    }
}
