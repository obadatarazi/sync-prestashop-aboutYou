<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class IntegrationException extends \RuntimeException
{
    public static function fromHttpFailure(string $service, string $method, string $url, int $status, string $body = ''): self
    {
        $suffix = $body !== '' ? ' - ' . mb_substr(trim($body), 0, 300) : '';
        return new self(sprintf('%s %s %s failed with HTTP %d%s', $service, strtoupper($method), $url, $status, $suffix));
    }
}
