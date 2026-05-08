<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class IntegrationException extends \RuntimeException
{
    public static function fromHttpFailure(string $service, string $method, string $url, int $status, string $body = ''): self
    {
        $trimmed = trim($body);
        $suffix = $trimmed !== '' ? ' - ' . mb_substr($trimmed, 0, 300) : '';
        $lower = strtolower($trimmed);
        $htmlEdge = $trimmed !== ''
            && (str_starts_with($lower, '<!doctype') || str_starts_with($lower, '<html'));
        $hint = '';
        if ($htmlEdge && ($status === 403 || $status === 401)) {
            $hint = ' [HTML response, not API JSON: usually CDN/WAF blocked this server, wrong host/URL, or TLS/client fingerprint — confirm AY_API_KEY and AY_BASE_URL, try `curl` from the same host, ask About You about IP allowlisting.]';
        } elseif (in_array($status, [502, 503, 504], true)) {
            $hint = ' [Transient upstream error — safe to retry with backoff.]';
        } elseif ($status === 429) {
            $hint = ' [Rate limited — honor Retry-After when present and widen AY_MIN_INTERVAL_MS / adaptive throttle if this persists during product or order sync.]';
        }
        return new self(sprintf('%s %s %s failed with HTTP %d%s%s', $service, strtoupper($method), $url, $status, $suffix, $hint));
    }
}
