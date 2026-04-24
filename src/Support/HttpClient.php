<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class HttpClient
{
    private array $lastCallAt = [];

    public function __construct(
        private readonly int $defaultTimeout = 15,
        private readonly int $connectTimeout = 8,
        private readonly int $maxRetries = 3,
    ) {
    }

    public function request(
        string $service,
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        array $options = []
    ): array {
        $method = strtoupper($method);
        $timeout = (int) ($options['timeout'] ?? $this->defaultTimeout);
        $connectTimeout = (int) ($options['connect_timeout'] ?? $this->connectTimeout);
        $minIntervalMs = (int) ($options['min_interval_ms'] ?? 0);
        $expectJson = (bool) ($options['expect_json'] ?? true);

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;
            $this->applyRateLimit($service, $minIntervalMs);

            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize HTTP client');
            }

            $normalizedHeaders = [];
            foreach ($headers as $name => $value) {
                $normalizedHeaders[] = is_int($name) ? $value : $name . ': ' . $value;
            }

            if (is_array($body)) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $normalizedHeaders[] = 'Content-Type: application/json';
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $normalizedHeaders,
            ]);

            if ($body !== null && $method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            if ($errno !== 0 || $raw === false) {
                $lastError = new \RuntimeException(sprintf('%s request error: %s', $service, $error ?: 'unknown curl error'));
                if ($attempt <= $this->maxRetries) {
                    usleep($this->retryDelayMicros($attempt));
                    continue;
                }

                throw $lastError;
            }

            $headerText = substr($raw, 0, $headerSize);
            $bodyText = substr($raw, $headerSize);
            $responseHeaders = $this->parseHeaders($headerText);

            if ($status === 429 || ($status >= 500 && $status < 600)) {
                $lastError = IntegrationException::fromHttpFailure($service, $method, $url, $status, $bodyText);
                if ($attempt <= $this->maxRetries) {
                    $retryAfter = (int) ($responseHeaders['retry-after'] ?? 0);
                    if ($retryAfter > 0) {
                        sleep($retryAfter);
                    } else {
                        usleep($this->retryDelayMicros($attempt));
                    }
                    continue;
                }
            }

            if ($status < 200 || $status >= 300) {
                throw IntegrationException::fromHttpFailure($service, $method, $url, $status, $bodyText);
            }

            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => $bodyText,
                'json' => $expectJson && trim($bodyText) !== '' ? json_decode($bodyText, true) : null,
            ];
        }

        throw $lastError ?? new \RuntimeException(sprintf('%s request failed without a recoverable response', $service));
    }

    private function applyRateLimit(string $service, int $minIntervalMs): void
    {
        if ($minIntervalMs <= 0) {
            return;
        }

        $now = (int) floor(microtime(true) * 1000);
        $last = $this->lastCallAt[$service] ?? 0;
        $wait = $minIntervalMs - ($now - $last);
        if ($wait > 0) {
            usleep($wait * 1000);
        }
        $this->lastCallAt[$service] = (int) floor(microtime(true) * 1000);
    }

    private function parseHeaders(string $headerText): array
    {
        $headers = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    private function retryDelayMicros(int $attempt): int
    {
        $base = (int) ((2 ** max(0, $attempt - 1)) * 250_000);
        $jitter = random_int(0, 120_000);
        return min(5_000_000, $base + $jitter);
    }
}
