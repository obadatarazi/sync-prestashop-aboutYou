<?php

declare(strict_types=1);

namespace SyncBridge\Support;

/**
 * AY docs-derived policy snapshot used at runtime.
 *
 * This class intentionally avoids outbound MCP calls at runtime; instead, it
 * provides a stable policy map sourced from MCP documentation review.
 */
final class AyDocsPolicy
{
    /**
     * @var array<string,int>
     */
    private const ENDPOINT_LIMITS_PER_MIN = [
        '/orders/' => 100,
        '/orders/ship' => 50,
        '/orders/cancel' => 50,
        '/orders/return' => 50,
        '/products/' => 100,
        '/products/status' => 100,
        '/products/stocks' => 1000,
        '/products/prices' => 200,
        '/results/products' => 200,
        '/results/status' => 200,
        '/results/stocks' => 200,
        '/results/prices' => 200,
    ];

    /**
     * @var list<string>
     */
    private const DEPRECATED_WEBHOOK_EVENTS = [
        'order.shipped',
        'order.cancelled',
    ];

    public function minIntervalMsForPath(string $path, int $fallbackMs = 650): int
    {
        $path = '/' . ltrim($path, '/');
        foreach (self::ENDPOINT_LIMITS_PER_MIN as $prefix => $limitPerMinute) {
            if (str_starts_with($path, $prefix)) {
                // Keep a small safety margin under documented limits.
                return (int) max(50, ceil((60_000 / max(1, $limitPerMinute)) * 1.15));
            }
        }

        return $fallbackMs;
    }

    public function classifyAyError(string $message): string
    {
        $m = strtolower($message);
        // Prefer contract/payload validation signals over generic retry hints.
        // Some AY responses append "Retry later..." even for validation errors.
        if (
            str_contains($m, 'missing attribute for group')
            || str_contains($m, 'size not found')
            || str_contains($m, 'product master not found')
            || str_contains($m, 'missing required')
            || str_contains($m, 'invalid')
            || str_contains($m, 'required')
            || str_contains($m, 'schema')
        ) {
            return 'ay_validation';
        }
        if (str_contains($m, '429') || str_contains($m, 'rate limit')) {
            return 'ay_rate_limit';
        }
        if (str_contains($m, '401') || str_contains($m, '403') || str_contains($m, 'api key')) {
            return 'ay_auth';
        }
        if (str_contains($m, 'timeout') || str_contains($m, 'timed out')) {
            return 'ay_timeout';
        }
        if (str_contains($m, 'deprecated')) {
            return 'ay_deprecated';
        }

        return 'ay_unknown';
    }

    public function isDeprecatedWebhookEvent(string $event): bool
    {
        return in_array(strtolower(trim($event)), self::DEPRECATED_WEBHOOK_EVENTS, true);
    }

    public function snapshotPayload(): array
    {
        return [
            'generated_at' => gmdate('c'),
            'endpoint_limits_per_min' => self::ENDPOINT_LIMITS_PER_MIN,
            'deprecated_webhook_events' => self::DEPRECATED_WEBHOOK_EVENTS,
        ];
    }
}
