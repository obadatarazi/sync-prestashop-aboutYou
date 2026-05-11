<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Support\HttpClient;
use App\Support\SyncFlags;
use App\Support\AttributeTypeGuesser;
use App\Support\AyDocsPolicy;

final class AboutYouClient
{
    private string $baseUrl;
    private string $apiKey;
    private AyDocsPolicy $policy;
    /** @var array<string,array|null> */
    private array $orderCache = [];
    private bool $verboseDebugEnabled;
    private string $verboseDebugPath;

    public function __construct(private readonly HttpClient $http)
    {
        $this->baseUrl = rtrim((string) ($_ENV['AY_BASE_URL'] ?? 'https://partner.aboutyou.com/api/v1'), '/');
        $this->apiKey = (string) ($_ENV['AY_API_KEY'] ?? '');
        $this->verboseDebugEnabled = filter_var($_ENV['AY_DEBUG_VERBOSE'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->verboseDebugPath = (string) ($_ENV['AY_DEBUG_LOG_PATH'] ?? (__DIR__ . '/../../logs/ay_http_debug.log'));

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
            foreach ($this->extractSuccessfulRequests($result) as $requestItem) {
                if (!is_array($requestItem)) {
                    continue;
                }
                $styleKey = trim((string) ($requestItem['style_key'] ?? $requestItem['styleKey'] ?? ''));
                if ($styleKey !== '') {
                    $styleKeys[$styleKey] = true;
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
        $perPage = min(100, max(1, (int) ($_ENV['AY_ORDER_PAGE_SIZE'] ?? 100)));
        $maxPages = max(1, min(500, (int) ($_ENV['AY_ORDERS_MAX_PAGES'] ?? 100)));

        do {
            $query = [
                'per_page' => $perPage,
                'page' => $page,
            ];
            if ($since !== null && trim($since) !== '') {
                $query['orders_from'] = $this->formatOrdersFromFilter($since);
            }
            if ($cursor !== null && $cursor !== '') {
                unset($query['page']);
                $query['cursor'] = $cursor;
            }

            $response = $this->request('GET', '/orders/', $query);
            $body = $response['json'] ?? [];
            if (!is_array($body)) {
                $body = [];
            }
            $items = $body['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $orders = array_merge($orders, $items);

            $rawNextCursor = $body['cursor'] ?? $body['next_cursor'] ?? null;
            $nextCursor = is_string($rawNextCursor) ? trim($rawNextCursor) : null;
            if ($nextCursor === '') {
                $nextCursor = null;
            }
            $cursor = $nextCursor;

            $pagination = $body['pagination'] ?? null;
            $hasNextByPagination = is_array($pagination)
                && (int) ($pagination['page'] ?? 0) > 0
                && (int) ($pagination['pages'] ?? 0) > 0
                && (int) ($pagination['page'] ?? 0) < (int) ($pagination['pages'] ?? 0);

            $itemCount = count($items);
            $fullPage = $itemCount >= $perPage;
            $hasMore = ($cursor !== null) || $hasNextByPagination || ($fullPage && $cursor === null);

            $page++;
        } while ($hasMore && $page <= $maxPages);

        if ($hasMore && $page > $maxPages) {
            error_log(sprintf(
                '[AY orders pagination warning] Hit AY_ORDERS_MAX_PAGES=%d with additional pages likely (since=%s, per_page=%d, last_cursor=%s, fetched=%d).',
                $maxPages,
                $since ?? '',
                $perPage,
                $cursor ?? 'none',
                count($orders)
            ));
        }

        return $orders;
    }

    public function getOrder(string $orderId): ?array
    {
        if (array_key_exists($orderId, $this->orderCache)) {
            return $this->orderCache[$orderId];
        }
        $response = $this->request('GET', '/orders/', ['order_number' => $orderId]);
        $items = $response['json']['items'] ?? [];
        $order = is_array($items) ? ($items[0] ?? null) : null;
        if ($order === null && ctype_digit($orderId)) {
            try {
                $response = $this->request('GET', '/orders/', ['id' => (int) $orderId]);
                $items = $response['json']['items'] ?? [];
                $order = is_array($items) ? ($items[0] ?? null) : null;
            } catch (\Throwable) {
                $order = null;
            }
        }
        $this->orderCache[$orderId] = $order;
        return $this->orderCache[$orderId];
    }

    public function searchCategories(?string $query = null, int $page = 1, int $perPage = 25, ?int $parentCategoryId = null): array
    {
        $params = [
            'page' => max(1, $page),
            'per_page' => min(100, max(1, $perPage)),
        ];
        if ($query !== null && trim($query) !== '') {
            $params['query'] = trim($query);
        }
        if ($parentCategoryId !== null && $parentCategoryId > 0) {
            $params['parent_category'] = $parentCategoryId;
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

    public function searchAttributeOptionsByGroupId(int $categoryId, int $groupId, ?string $query = null): array
    {
        if ($categoryId <= 0 || $groupId <= 0) {
            return [];
        }
        $groups = $this->getCategoryAttributeGroups($categoryId);
        $needle = strtolower(trim((string) $query));
        $results = [];

        foreach ($groups as $group) {
            if ((int) ($group['id'] ?? 0) !== $groupId) {
                continue;
            }
            $groupName = (string) ($group['name'] ?? '');
            foreach (($group['values'] ?? []) as $value) {
                $label = trim((string) ($value['label'] ?? $value['name'] ?? ''));
                if ($label === '') {
                    continue;
                }
                if ($needle !== '' && !str_contains(strtolower($label), $needle)) {
                    continue;
                }
                $results[] = [
                    'id' => (int) ($value['id'] ?? 0),
                    'label' => $label,
                    'group_name' => $groupName,
                ];
            }
            break;
        }
        usort($results, static fn (array $a, array $b): int => $a['label'] <=> $b['label']);
        return $results;
    }

    /**
     * @return list<array{id:int,name:string,key:string,default_ay_id:int,values:list<array{id:int,label:string}>}>
     */
    public function listCategoryAttributeGroups(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        return $this->getCategoryAttributeGroups($categoryId);
    }

    public function getRequiredCategoryMetadata(int $categoryId): array
    {
        $groups = $this->getCategoryAttributeGroups($categoryId);
        $requiredGroups = [];
        $requiredTextFields = [];
        $assumeRequiredWhenMissingFlag = filter_var(
            $_ENV['AY_ASSUME_CATEGORY_GROUPS_REQUIRED'] ?? false,
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

    public function getProducts(array $filters = []): array
    {
        $query = array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '');
        $response = $this->request('GET', '/products/', $query);
        $payload = $response['json'] ?? [];
        $items = is_array($payload) && array_is_list($payload)
            ? $payload
            : ($payload['items'] ?? $payload['products'] ?? []);
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
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
        $status = strtolower(trim((string) ($result['status'] ?? '')));
        if ($status === 'failed') {
            return false;
        }
        $items = $this->normalizeBatchItems($result);
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (!($item['success'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    private function submitBatch(string $method, string $path, array $body): string
    {
        try {
            $response = $this->request($method, $path, [], $body);
        } catch (\Throwable $e) {
            if ($method === 'POST' && $path === '/products/') {
                $response = $this->request($method, '/products', [], $body);
            } else {
                throw $e;
            }
        }
        $batchId = (string) ($response['json']['batchRequestId'] ?? $response['json']['batch_request_id'] ?? '');
        if ($batchId === '') {
            throw new \RuntimeException('AboutYou batch request did not return a batch ID for ' . $path);
        }

        return $batchId;
    }

    private function pollBatch(string $path, string $batchId): array
    {
        // About You uses pending → processing → completed|failed|retry; large product batches can exceed ~30s.
        $attempts = max(3, (int) ($_ENV['AY_BATCH_POLL_ATTEMPTS'] ?? 25));
        $baseSleepMs = max(400, (int) ($_ENV['AY_BATCH_POLL_MS'] ?? 2000));

        $lastStatus = '';
        for ($i = 0; $i < $attempts; $i++) {
            $response = $this->request('GET', $path, ['batch_request_id' => $batchId]);
            $body = $response['json'] ?? [];
            // Never default to completed: a missing status must keep polling until attempts exhausted.
            $status = strtolower(trim((string) ($body['status'] ?? 'pending')));
            $lastStatus = $status;

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

        throw new \RuntimeException(sprintf(
            'Timed out while waiting for AboutYou batch result %s (last status=%s after %d polls). Increase AY_BATCH_POLL_ATTEMPTS and/or AY_BATCH_POLL_MS in .env if batches are large or the platform is slow.',
            $batchId,
            $lastStatus !== '' ? $lastStatus : 'unknown',
            $attempts
        ));
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
                'retryable' => !$success && in_array($reasonCode, ['ay_rate_limit', 'ay_timeout', 'ay_transient', 'ay_unknown'], true),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractSuccessfulRequests(array $result): array
    {
        $items = $result['items'] ?? [];
        $requests = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $errors = $item['errors'] ?? [];
            $success = (bool) ($item['success'] ?? empty($errors));
            if (!$success) {
                continue;
            }
            $request = $item['requestItem'] ?? $item['request_item'] ?? null;
            if (is_array($request)) {
                $requests[] = $request;
            }
        }
        return $requests;
    }

    /**
     * Normalizes `orders_from` to RFC3339 / ISO-8601 in UTC for GET /orders/.
     */
    private function formatOrdersFromFilter(string $since): string
    {
        $since = trim($since);
        if ($since === '') {
            return gmdate('c', time() - 86400);
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $since)) {
                return (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone('UTC'))->format('c');
            }
        } catch (\Throwable) {
            // fall through to strtotime
        }
        $suffix = preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $since) ? '' : ' UTC';
        $ts = strtotime($since . $suffix);
        if ($ts === false) {
            $ts = time() - 86400;
        }

        return gmdate('c', $ts);
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

        $userAgent = trim((string) ($_ENV['AY_USER_AGENT'] ?? ''));
        if ($userAgent === '') {
            $userAgent = 'syncbridge/prestashop-aboutyou (PHP)';
        }

        $headers = [
            'X-API-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $userAgent,
        ];
        $options = [
            'expect_json' => true,
            'min_interval_ms' => $policyInterval,
            'timeout' => (int) ($_ENV['AY_TIMEOUT_SEC'] ?? 60),
        ];

        try {
            $response = $this->http->request('aboutyou', $method, $url, $headers, $body, $options);
            $this->debugVerbose($method, $url, $headers, $body, $response);
            return $response;
        } catch (\Throwable $e) {
            $this->debugVerbose($method, $url, $headers, $body, null, $e);
            throw $e;
        }
    }

    private function getCategoryAttributeGroups(int $categoryId): array
    {
        $candidates = [
            '/categories/' . $categoryId . '/attribute-groups',
        ];
        // Keep AY_ENABLE_LEGACY_ATTRIBUTE_ENDPOINTS in .env for backward compatibility;
        // fallback candidates are now always attempted after the preferred endpoint.
        $legacyCandidates = [
            '/categories/' . $categoryId . '/attribute_groups',
            '/categories/' . $categoryId . '/attributes',
            '/categories/' . $categoryId . '/attributes/',
            '/attributes',
        ];
        // Always keep legacy fallback path available to avoid endpoint regressions.
        $candidates = array_merge($candidates, $legacyCandidates);

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

    private function debugVerbose(
        string $method,
        string $url,
        array $headers,
        array|string|null $body,
        ?array $response = null,
        ?\Throwable $exception = null
    ): void {
        if (!$this->verboseDebugEnabled) {
            return;
        }

        $dir = dirname($this->verboseDebugPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $payload = [
            'ts' => gmdate('c'),
            'method' => strtoupper($method),
            'url' => $url,
            'request_headers' => $this->maskHeaders($headers),
            'request_body' => is_array($body)
                ? $body
                : ($body !== null ? $this->decodeJsonString($body) : null),
            'response_status' => $response['status'] ?? null,
            'response_headers' => $response['headers'] ?? null,
            'response_body' => isset($response['body']) ? $this->decodeJsonString((string) $response['body']) : null,
            'exception' => $exception ? [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
            ] : null,
            'account' => [
                'base_url' => $this->baseUrl,
                'api_key_prefix' => substr($this->apiKey, 0, 12),
                'merchant_id' => (string) ($_ENV['AY_MERCHANT_ID'] ?? ''),
                'test_mode' => SyncFlags::testMode() ? 'true' : 'false',
            ],
        ];

        @file_put_contents(
            $this->verboseDebugPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function maskHeaders(array $headers): array
    {
        $masked = [];
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-api-key') {
                $masked[$name] = substr((string) $value, 0, 8) . '***';
                continue;
            }
            $masked[$name] = $value;
        }
        return $masked;
    }

    private function decodeJsonString(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $trimmed;
        }
    }
}
