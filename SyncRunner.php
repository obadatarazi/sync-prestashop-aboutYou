<?php

namespace SyncBridge\Services;

use SyncBridge\Database\RetryJobRepository;
use SyncBridge\Database\SyncRunRepository;
use SyncBridge\Database\ProductRepository;
use SyncBridge\Integration\AboutYouClient;
use SyncBridge\Integration\AboutYouMapper;
use SyncBridge\Integration\PrestaShopClient;
use SyncBridge\Support\HttpClient;
use SyncBridge\Support\ImageNormalizer;

final class SyncRunner
{
    private SyncRunRepository $runs;
    private RetryJobRepository $retryJobs;

    public function __construct()
    {
        $this->runs = new SyncRunRepository();
        $this->retryJobs = new RetryJobRepository();
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

            $this->runs->finishRun($runId, true, microtime(true) - $started);

            return [
                'ok' => true,
                'run_id' => $runId,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $this->runs->finishRun($runId, false, microtime(true) - $started);
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
        return [
            'retried' => count($this->retryJobs->listPending((int) ($options['limit'] ?? 50))),
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
}
