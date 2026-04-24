<?php

declare(strict_types=1);

namespace SyncBridge\Integration;

use SyncBridge\Support\HttpClient;
use SyncBridge\Support\AttributeTypeGuesser;
use SyncBridge\Support\AyDocsPolicy;

final class AboutYouClient
{
    private string $baseUrl;
    private string $apiKey;
    private AyDocsPolicy $policy;
    /** @var array<string,array|null> */
    private array $orderCache = [];

    public function __construct(private readonly HttpClient $http)
    {
        $this->baseUrl = rtrim((string) ($_ENV['AY_BASE_URL'] ?? 'https://partner.aboutyou.com/api/v1'), '/');
        $this->apiKey = (string) ($_ENV['AY_API_KEY'] ?? '');

        if ($this->apiKey === '') {
            throw new \RuntimeException('AboutYou is not configured. Set AY_API_KEY.');
        }
        $this->policy = new AyDocsPolicy();
    }

    public function upsertProducts(array $variants): array
    {
        if ($variants === []) {
            return [];
        }

        $batchId = $this->submitBatch('POST', '/products/', ['items' => array_values($variants)]);
        $result = $this->pollBatch('/results/products', $batchId);

        if (filter_var($_ENV['AY_AUTO_PUBLISH'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $styleKeys = [];
            foreach ($variants as $variant) {
                if (!empty($variant['style_key'])) {
                    $styleKeys[$variant['style_key']] = true;
                }
            }
            if ($styleKeys !== []) {
                $publishBatchId = $this->submitBatch('PUT', '/products/status', [
                    'items' => array_map(static fn (string $styleKey): array => [
                        'style_key' => $styleKey,
                        'status' => 'published',
                    ], array_keys($styleKeys)),
                ]);
                $publishResult = $this->pollBatch('/results/status', $publishBatchId);
                $result['items'] = array_merge($result['items'] ?? [], $publishResult['items'] ?? []);
            }
        }

        return $this->normalizeBatchItems($result);
    }

    public function updateStocks(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $batchId = $this->submitBatch('PUT', '/products/stocks', ['items' => array_values($items)]);
        $result = $this->pollBatch('/results/stocks', $batchId);
        return $this->normalizeBatchItems($result);
    }

    public function updatePrices(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $batchId = $this->submitBatch('PUT', '/products/prices', ['items' => array_values($items)]);
        $result = $this->pollBatch('/results/prices', $batchId);
        return $this->normalizeBatchItems($result);
    }

    public function getNewOrders(?string $since = null): array
    {
        $page = 1;
        $cursor = null;
        $orders = [];

        do {
            $query = [
                'per_page' => min(100, max(1, (int) ($_ENV['AY_ORDER_PAGE_SIZE'] ?? 100))),
                'page' => $page,
            ];
            if ($since !== null) {
                $query['orders_from'] = gmdate('c', strtotime($since));
            }
            if ($cursor) {
                unset($query['page']);
                $query['cursor'] = $cursor;
            }

            $response = $this->request('GET', '/orders/', $query);
            $body = $response['json'] ?? [];
            $items = $body['items'] ?? [];
            $orders = array_merge($orders, $items);
            $cursor = $body['cursor'] ?? $body['next_cursor'] ?? null;
            $page++;
        } while (($cursor !== null || ($items ?? []) !== []) && count($items ?? []) === (int) ($query['per_page'] ?? 100) && $page <= 100);

        return $orders;
    }

    public function getOrder(string $orderId): ?array
    {
        if (array_key_exists($orderId, $this->orderCache)) {
            return $this->orderCache[$orderId];
        }
        $response = $this->request('GET', '/orders/', ['order_number' => $orderId]);
        $items = $response['json']['items'] ?? [];
        $this->orderCache[$orderId] = $items[0] ?? null;
        return $this->orderCache[$orderId];
    }

    public function searchCategories(?string $query = null, int $page = 1, int $perPage = 25): array
    {
        $params = [
            'page' => max(1, $page),
            'per_page' => min(100, max(1, $perPage)),
        ];
        if ($query !== null && trim($query) !== '') {
            $params['query'] = trim($query);
        }

        try {
            $response = $this->request('GET', '/categories/', $params);
        } catch (\Throwable) {
            $response = $this->request('GET', '/categories', $params);
        }
        $data = $response['json'] ?? ['items' => [], 'pagination' => null];
        return $data;
    }

    public function searchAttributeOptions(int $categoryId, string $type, ?string $query = null): array
    {
        $groups = $this->getCategoryAttributeGroups($categoryId);
        $needle = strtolower(trim((string) $query));
        $type = strtolower(trim($type));
        $effectiveType = $type === 'second_size' ? 'size' : $type;
        $results = [];
        $allMatches = [];

        foreach ($groups as $group) {
            $groupName = (string) ($group['name'] ?? '');
            $groupType = strtolower((string) ($group['type'] ?? $groupName));
            if ($effectiveType === 'color' && !AttributeTypeGuesser::isColor($groupType)) {
                continue;
            }
            if ($effectiveType === 'size' && !AttributeTypeGuesser::isSize($groupType)) {
                continue;
            }

            foreach (($group['values'] ?? []) as $value) {
                $label = trim((string) ($value['label'] ?? $value['name'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $option = [
                    'id' => (int) ($value['id'] ?? 0),
                    'label' => $label,
                    'group_name' => $groupName,
                ];
                $allMatches[] = $option;
                if ($needle !== '' && !str_contains(strtolower($label), $needle) && !str_contains(strtolower($groupName), $needle)) {
                    continue;
                }
                $results[] = $option;
            }
        }

        if ($results === [] && $needle !== '') {
            $results = $allMatches;
        }

        usort($results, static fn (array $a, array $b): int => [$a['group_name'], $a['label']] <=> [$b['group_name'], $b['label']]);
        return $results;
    }

    public function getRequiredCategoryMetadata(int $categoryId): array
    {
        $groups = $this->getCategoryAttributeGroups($categoryId);
        $requiredGroups = [];
        $requiredTextFields = [];
        $assumeRequiredWhenMissingFlag = filter_var(
            $_ENV['AY_ASSUME_CATEGORY_GROUPS_REQUIRED'] ?? true,
            FILTER_VALIDATE_BOOLEAN
        );
        foreach ($groups as $group) {
            $hasRequiredFlag = filter_var($group['has_required_flag'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $isRequired = $hasRequiredFlag
                ? filter_var($group['required'] ?? false, FILTER_VALIDATE_BOOLEAN)
                : $assumeRequiredWhenMissingFlag;
            if (!$isRequired) {
                continue;
            }
            $groupId = (int) ($group['id'] ?? 0);
            $groupName = trim((string) ($group['name'] ?? ''));
            $groupKey = trim((string) ($group['key'] ?? ''));
            $defaultAyId = (int) ($group['default_ay_id'] ?? 0);
            $values = $group['values'] ?? [];
            if ($groupId > 0 && is_array($values) && $values !== []) {
                $requiredGroups[] = [
                    'id' => $groupId,
                    'name' => $groupKey !== '' ? $groupKey : $groupName,
                    'required' => true,
                    'default_ay_id' => $defaultAyId,
                ];
                continue;
            }
            $textKey = strtolower($groupKey !== '' ? $groupKey : $groupName);
            if ($textKey !== '') {
                $requiredTextFields[] = $textKey;
            }
        }

        $fallbackText = trim((string) ($_ENV['AY_FALLBACK_REQUIRED_TEXT_FIELDS'] ?? 'material_composition_textile'));
        if ($fallbackText !== '') {
            foreach (explode(',', $fallbackText) as $field) {
                $field = strtolower(trim($field));
                if ($field !== '') {
                    $requiredTextFields[] = $field;
                }
            }
        }

        return [
            'required_groups' => $requiredGroups,
            'required_text_fields' => array_values(array_unique($requiredTextFields)),
        ];
    }

    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            throw new \RuntimeException('AboutYou order not found: ' . $orderId);
        }

        $itemIds = array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $order['order_items'] ?? []
        );
        $itemIds = array_values(array_filter($itemIds));

        if ($itemIds === []) {
            return true;
        }

        return match ($status) {
            'processing', 'open' => true,
            'shipped' => $this->shipOrderItems($itemIds, $order['carrier_key'] ?? '', $extra),
            'cancelled' => $this->changeOrderItemStatus('/orders/cancel', '/results/cancel-orders', $itemIds, []),
            'returned' => $this->changeOrderItemStatus('/orders/return', '/results/return-orders', $itemIds, [
                'return_tracking_key' => (string) ($extra['return_tracking_key'] ?? $extra['tracking_number'] ?? ''),
            ]),
            default => false,
        };
    }

    public function healthCheck(): array
    {
        $response = $this->request('GET', '/orders/', ['per_page' => 1]);
        return [
            'ok' => true,
            'status' => $response['status'],
        ];
    }

    public function findExistingStyleKeys(array $styleKeys): array
    {
        $styleKeys = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $styleKeys),
            static fn (string $value): bool => $value !== ''
        )));
        if ($styleKeys === []) {
            return [];
        }

        $found = [];
        $lastException = null;
        $hadSuccessfulRequest = false;

        foreach (array_chunk($styleKeys, 25) as $chunk) {
            $queryCandidates = [
                ['style_keys' => implode(',', $chunk), 'per_page' => 100],
                ['style_key' => implode(',', $chunk), 'per_page' => 100],
            ];

            foreach ($queryCandidates as $query) {
                try {
                    $response = $this->request('GET', '/products/', $query);
                    $hadSuccessfulRequest = true;
                } catch (\Throwable $first) {
                    try {
                        $response = $this->request('GET', '/products', $query);
                        $hadSuccessfulRequest = true;
                    } catch (\Throwable $second) {
                        $lastException = $second;
                        continue;
                    }
                }

                $payload = $response['json'] ?? [];
                $items = is_array($payload) && array_is_list($payload)
                    ? $payload
                    : ($payload['items'] ?? $payload['products'] ?? []);
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $styleKey = trim((string) ($item['style_key'] ?? $item['styleKey'] ?? ''));
                    if ($styleKey !== '') {
                        $found[$styleKey] = true;
                    }
                }
            }
        }

        if (!$hadSuccessfulRequest) {
            throw $lastException ?? new \RuntimeException('Unable to query AboutYou products for style key recheck.');
        }

        return array_keys($found);
    }

    private function shipOrderItems(array $itemIds, string $carrierKey, array $extra): bool
    {
        return $this->changeOrderItemStatus('/orders/ship', '/results/ship-orders', $itemIds, [
            'carrier_key' => $carrierKey !== '' ? $carrierKey : (string) ($_ENV['AY_DEFAULT_CARRIER_KEY'] ?? ''),
            'shipment_tracking_key' => (string) ($extra['tracking_number'] ?? ''),
            'return_tracking_key' => (string) ($extra['return_tracking_key'] ?? ''),
        ]);
    }

    private function changeOrderItemStatus(string $endpoint, string $resultEndpoint, array $itemIds, array $extra): bool
    {
        $payload = ['items' => [array_filter(['order_items' => $itemIds] + $extra, static fn ($v) => $v !== '' && $v !== null)]];
        $batchId = $this->submitBatch('POST', $endpoint, $payload);
        $result = $this->pollBatch($resultEndpoint, $batchId);
        foreach ($this->normalizeBatchItems($result) as $item) {
            if (!($item['success'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    private function submitBatch(string $method, string $path, array $body): string
    {
        $response = $this->request($method, $path, [], $body);
        $batchId = (string) ($response['json']['batchRequestId'] ?? $response['json']['batch_request_id'] ?? '');
        if ($batchId === '') {
            throw new \RuntimeException('AboutYou batch request did not return a batch ID for ' . $path);
        }

        return $batchId;
    }

    private function pollBatch(string $path, string $batchId): array
    {
        $attempts = max(3, (int) ($_ENV['AY_BATCH_POLL_ATTEMPTS'] ?? 10));
        $baseSleepMs = max(400, (int) ($_ENV['AY_BATCH_POLL_MS'] ?? 1500));

        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->request('GET', $path, ['batch_request_id' => $batchId]);
            $body = $response['json'] ?? [];
            $status = strtolower((string) ($body['status'] ?? 'completed'));

            if (in_array($status, ['completed', 'success'], true)) {
                return $body;
            }

            if (in_array($status, ['failed'], true)) {
                return $body;
            }

            if ($status === 'retry') {
                // Retry status means backend asked us to back off.
                $sleepMs = min(15_000, (int) round($baseSleepMs * 2.5));
            } else {
                // Progressive backoff to reduce hot polling load.
                $sleepMs = min(10_000, $baseSleepMs + ($i * 350));
            }
            usleep($sleepMs * 1000);
        }

        throw new \RuntimeException('Timed out while waiting for AboutYou batch result ' . $batchId);
    }

    private function normalizeBatchItems(array $result): array
    {
        $items = $result['items'] ?? [];
        $normalized = [];

        foreach ($items as $item) {
            $errors = $item['errors'] ?? [];
            $success = (bool) ($item['success'] ?? empty($errors));
            $errorText = implode('; ', array_map('strval', $errors));
            $reasonCode = $success ? 'success' : $this->policy->classifyAyError($errorText);
            $normalized[] = [
                'success' => $success,
                'request' => $item['requestItem'] ?? $item['request_item'] ?? null,
                'errors' => $errors,
                'error' => $success ? null : $errorText,
                'reason_code' => $reasonCode,
                'retryable' => !$success && in_array($reasonCode, ['ay_rate_limit', 'ay_timeout', 'ay_unknown'], true),
            ];
        }

        return $normalized;
    }

    private function request(string $method, string $path, array $query = [], array|string|null $body = null): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $baseInterval = (int) ($_ENV['AY_MIN_INTERVAL_MS'] ?? 650);
        $adaptiveThrottle = filter_var($_ENV['FEATURE_AY_ADAPTIVE_THROTTLE'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $policyInterval = $adaptiveThrottle
            ? $this->policy->minIntervalMsForPath($path, $baseInterval)
            : $baseInterval;

        return $this->http->request('aboutyou', $method, $url, [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
        ], $body, [
            'expect_json' => true,
            'min_interval_ms' => $policyInterval,
            'timeout' => (int) ($_ENV['AY_TIMEOUT_SEC'] ?? 60),
        ]);
    }

    private function getCategoryAttributeGroups(int $categoryId): array
    {
        $candidates = [
            '/categories/' . $categoryId . '/attribute-groups',
            '/categories/' . $categoryId . '/attribute_groups',
            '/categories/' . $categoryId . '/attributes',
            '/categories/' . $categoryId . '/attributes/',
            '/attributes',
        ];

        $payload = null;
        $last = null;
        foreach ($candidates as $path) {
            try {
                $query = [];
                if ($path === '/attributes') {
                    $query = [
                        'category_id' => $categoryId,
                        'per_page' => 250,
                    ];
                }
                $payload = $this->request('GET', $path, $query)['json'] ?? null;
                if (is_array($payload)) {
                    break;
                }
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        if (!is_array($payload)) {
            throw $last ?? new \RuntimeException('Could not load AboutYou category attribute metadata.');
        }

        $rawGroups = [];
        if (array_is_list($payload)) {
            $rawGroups = $payload;
        } else {
            $rawGroups = $payload['items']
                ?? $payload['data']
                ?? $payload['attribute_groups']
                ?? $payload['attributes']
                ?? [];
        }
        $groups = [];
        foreach ($rawGroups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $name = (string) ($group['frontend_name'] ?? $group['name'] ?? $group['label'] ?? $group['key'] ?? '');
            $values = $group['attributes'] ?? $group['values'] ?? $group['items'] ?? $group['options'] ?? [];
            if (!is_array($values)) {
                continue;
            }
            $groups[] = [
                'id' => (int) ($group['id'] ?? 0),
                'name' => $name,
                'key' => (string) ($group['key'] ?? ''),
                'required' => $group['required'] ?? null,
                'has_required_flag' => array_key_exists('required', $group),
                'default_ay_id' => (int) ($group['default'] ?? $group['default_id'] ?? 0),
                'type' => (string) ($group['frontend_name'] ?? $group['type'] ?? $group['key'] ?? $group['name'] ?? $name),
                'values' => array_values(array_filter(array_map(static fn (array $item): array => [
                    'id' => (int) ($item['id'] ?? 0),
                    'label' => (string) ($item['frontend_name'] ?? $item['label'] ?? $item['name'] ?? $item['value'] ?? ''),
                ], array_filter($values, 'is_array')), static fn (array $item): bool => $item['id'] > 0 && trim($item['label']) !== '')),
            ];
        }

        return $groups;
    }
}
