<?php

namespace SyncBridge\Services;

use SyncBridge\Database\RetryJobRepository;
use SyncBridge\Database\SyncRunRepository;
use SyncBridge\Database\ProductRepository;
use SyncBridge\Database\SyncMetricsRepository;
use SyncBridge\Integration\AboutYouClient;
use SyncBridge\Integration\AboutYouMapper;
use SyncBridge\Integration\PrestaShopClient;
use SyncBridge\Support\HttpClient;
use SyncBridge\Support\ImageNormalizer;

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
        $started = microtime(true);
        $this->runs->startRun($runId, $command);

        $http = new HttpClient(
            (int) ($_ENV['HTTP_TIMEOUT_SEC'] ?? 30),
            (int) ($_ENV['HTTP_MAX_RETRIES'] ?? 3)
        );
        $ps = new PrestaShopClient($http);
        $ay = new AboutYouClient($http);
        $mapper = new AboutYouMapper();
        $imageNormalizer = $this->buildImageNormalizer($http);
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
                    ? (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer))->syncForProductIds($psProductIds)
                    : (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer))->syncAll(),
                'products:inc' => $psProductIds !== []
                    ? (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer))->syncForProductIds($psProductIds)
                    : ((!$hasSeededCatalog && !isset($options['since']))
                        ? (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer))->syncAll()
                        : (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer))
                            ->syncIncremental($incrementalSince ?? date('Y-m-d H:i:s', strtotime('-1 day')))),
                'stock' => (new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer))
                    ->syncStockAndPrices($options['since'] ?? $this->runs->getLastSuccessfulStartedAt('stock') ?? date('Y-m-d H:i:s', strtotime('-2 hours'))),
                'orders' => (new OrderSyncService($runId, $ps, $ay, $mapper))
                    ->importNewOrders($options['since'] ?? $this->runs->getLastSuccessfulStartedAt('orders') ?? date('Y-m-d H:i:s', strtotime('-1 day'))),
                'order-status' => (new OrderSyncService($runId, $ps, $ay, $mapper))
                    ->pushOrderStatusUpdates($options['since'] ?? null),
                'all' => $this->runAll($runId, $ps, $ay, $mapper, $imageNormalizer),
                'retry' => $this->runRetryQueue($options),
                'status' => ['ok' => true, 'run_id' => $runId, 'message' => 'Status command does not execute a sync run'],
                default => throw new \InvalidArgumentException('Unsupported sync command: ' . $command),
            };

            $elapsed = microtime(true) - $started;
            $summary = $this->extractRunSummary($result);
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
            ]);
            if (filter_var($_ENV['FEATURE_SYNC_METRICS'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                $this->metrics->recordRunMetric($runId, $command, 'run', 'elapsed_sec', round($elapsed, 3), $summary);
                $this->metrics->recordRunMetric($runId, $command, 'run', 'pushed', $summary['pushed']);
                $this->metrics->recordRunMetric($runId, $command, 'run', 'failed', $summary['failed']);
                $this->metrics->recordRunMetric($runId, $command, 'run', 'skipped', $summary['skipped']);
            }
            $this->runs->finishRun($runId, true, $elapsed);

            return [
                'ok' => true,
                'run_id' => $runId,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $elapsed = microtime(true) - $started;
            $this->runs->log($runId, 'error', 'metrics', 'sync_run_failed', [
                'command' => $command,
                'elapsed_sec' => round($elapsed, 3),
            ]);
            $this->runs->finishRun($runId, false, $elapsed);
            $this->runs->log($runId, 'critical', 'sync', $e->getMessage(), ['exception' => get_class($e)]);

            return [
                'ok' => false,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function runAll(
        string $runId,
        PrestaShopClient $ps,
        AboutYouClient $ay,
        AboutYouMapper $mapper,
        ?ImageNormalizer $imageNormalizer
    ): array {
        $productService = new ProductSyncService($runId, $ps, $ay, $mapper, $imageNormalizer);
        $orderService = new OrderSyncService($runId, $ps, $ay, $mapper);

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

    private function runRetryQueue(array $options): array
    {
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
                } elseif ($jobType === 'order_status') {
                    $result = $this->run('order-status', []);
                    if (!($result['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($result['error'] ?? 'order status retry failed'));
                    }
                } elseif ($jobType === 'order_import') {
                    $result = $this->run('orders', []);
                    if (!($result['ok'] ?? false)) {
                        throw new \RuntimeException((string) ($result['error'] ?? 'order import retry failed'));
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

    private function buildImageNormalizer(HttpClient $http): ?ImageNormalizer
    {
        if (!filter_var($_ENV['IMAGE_NORMALIZE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $baseUrl = rtrim((string) ($_ENV['IMAGE_PUBLIC_BASE_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            if ($appUrl !== '') {
                $baseUrl = $appUrl . '/ay-normalized';
            }
        }

        if ($baseUrl === '') {
            return null;
        }

        return new ImageNormalizer(
            $http,
            __DIR__ . '/public/ay-normalized',
            $baseUrl,
            1125,
            1500,
            (int) ($_ENV['IMAGE_JPEG_QUALITY'] ?? 92)
        );
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
