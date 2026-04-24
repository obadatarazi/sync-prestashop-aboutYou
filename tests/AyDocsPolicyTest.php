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
}
