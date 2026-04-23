<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SyncBridge\Services\ProductSyncService;
use SyncBridge\Support\ValidationException;

final class ProductSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['AY_STRICT_PREFLIGHT'] = 'true';
        $_ENV['AY_REQUIRE_CATEGORY_METADATA'] = 'true';
    }

    public function testInjectCategoryRequirementsAddsMetadataToProductPayload(): void
    {
        $ay = new class {
            public function getRequiredCategoryMetadata(int $categoryId): array
            {
                return [
                    'required_groups' => [
                        ['id' => 1400, 'name' => 'quantity_per_pack', 'required' => true, 'default_ay_id' => 889900],
                    ],
                    'required_text_fields' => ['material_composition_textile'],
                ];
            }
        };

        $service = new ProductSyncService(
            'testrun1234567890',
            new stdClass(),
            $ay,
            new class {
                public function calculateVariantRetailPrice(array $psProduct, array $variant): float
                {
                    return (float) ($psProduct['price'] ?? 0) + (float) ($variant['price'] ?? 0);
                }
            }
        );

        $payload = ['id' => 10, 'reference' => 'sku'];
        $invoke = Closure::bind(
            function (array &$payloadRef, int $categoryId): void {
                $this->injectCategoryRequirements($payloadRef, $categoryId);
            },
            $service,
            ProductSyncService::class
        );
        $invoke($payload, 1234);

        self::assertCount(1, $payload['ay_required_attribute_groups']);
        self::assertSame(1400, $payload['ay_required_attribute_groups'][0]['id']);
        self::assertSame(['material_composition_textile'], $payload['ay_required_text_fields']);
    }

    public function testInjectCategoryRequirementsThrowsInStrictModeWhenMetadataMissing(): void
    {
        $ay = new class {
            public function getRequiredCategoryMetadata(int $categoryId): array
            {
                throw new RuntimeException('404 metadata not found');
            }
        };

        $service = new ProductSyncService(
            'testrun_missingmeta',
            new stdClass(),
            $ay,
            new class {
                public function calculateVariantRetailPrice(array $psProduct, array $variant): float
                {
                    return (float) ($psProduct['price'] ?? 0) + (float) ($variant['price'] ?? 0);
                }
            }
        );

        $payload = ['id' => 10, 'reference' => 'sku'];
        $invoke = Closure::bind(
            function (array &$payloadRef, int $categoryId): void {
                $this->injectCategoryRequirements($payloadRef, $categoryId);
            },
            $service,
            ProductSyncService::class
        );

        $this->expectException(ValidationException::class);
        $invoke($payload, 57);
    }

    public function testInjectCategoryRequirementsUsesFallbackDefaultsWhenMetadataMissing(): void
    {
        $ay = new class {
            public function getRequiredCategoryMetadata(int $categoryId): array
            {
                throw new RuntimeException('404 metadata not found');
            }
        };

        $service = new class('testrun_fallbackmeta', new stdClass(), $ay, new class {
            public function calculateVariantRetailPrice(array $psProduct, array $variant): float
            {
                return (float) ($psProduct['price'] ?? 0) + (float) ($variant['price'] ?? 0);
            }
        }) extends ProductSyncService {
            protected function loadFallbackRequirementMetadataFromDefaults(int $categoryId): array
            {
                return [
                    'required_groups' => [
                        ['id' => 1712, 'name' => 'fitting', 'required' => true, 'default_ay_id' => 555001],
                    ],
                    'required_text_fields' => ['material_composition_textile'],
                ];
            }
        };

        $payload = ['id' => 10, 'reference' => 'sku'];
        $invoke = Closure::bind(
            function (array &$payloadRef, int $categoryId): void {
                $this->injectCategoryRequirements($payloadRef, $categoryId);
            },
            $service,
            ProductSyncService::class
        );
        $invoke($payload, 57);

        self::assertSame(1712, $payload['ay_required_attribute_groups'][0]['id']);
        self::assertSame(['material_composition_textile'], $payload['ay_required_text_fields']);
    }
}
