<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SyncBridge\Support\AyDocsPolicy;

final class AyDocsPolicyTest extends TestCase
{
    public function testClassifyValidationErrorEvenWhenRetryHintIsPresent(): void
    {
        $policy = new AyDocsPolicy();
        $message = 'Size not found; Missing attribute for group sport_technology with id 1858; Retry later or reduce sync batch size/rate.';

        self::assertSame('ay_validation', $policy->classifyAyError($message));
    }

    public function testClassifyPureRateLimitError(): void
    {
        $policy = new AyDocsPolicy();
        $message = '429 Too Many Requests: Rate limit exceeded';

        self::assertSame('ay_rate_limit', $policy->classifyAyError($message));
    }

    public function testClassifyTransientUpstream(): void
    {
        $policy = new AyDocsPolicy();
        self::assertSame('ay_transient', $policy->classifyAyError('Bad Gateway from seller API'));
        self::assertSame('ay_transient', $policy->classifyAyError('Service Unavailable'));
    }

    public function testMinIntervalCoversOrderResultBatches(): void
    {
        $policy = new AyDocsPolicy();
        $ship = $policy->minIntervalMsForPath('/results/ship-orders', 999);
        self::assertLessThan(999, $ship);
    }
}
