<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SyncBridge\Database\ProductRepository;
use SyncBridge\Integration\AboutYouClient;
use SyncBridge\Support\HttpClient;

final class ComparisonSupportTest extends TestCase
{
    public function testFindByPsIdsReturnsEmptyForInvalidIdsWithoutQueryingDatabase(): void
    {
        $repo = new ProductRepository();
        self::assertSame([], $repo->findByPsIds([0, -10, 'foo']));
    }

    public function testFindExistingStyleKeysReturnsEmptyWhenInputIsEmpty(): void
    {
        $_ENV['AY_API_KEY'] = 'test-key';
        $client = new AboutYouClient(new HttpClient());

        self::assertSame([], $client->findExistingStyleKeys([]));
        self::assertSame([], $client->findExistingStyleKeys(['', '   ']));
    }
}
