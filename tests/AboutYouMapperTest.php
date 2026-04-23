<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SyncBridge\Integration\AboutYouMapper;
use SyncBridge\Support\ValidationException;

final class AboutYouMapperTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['AY_BRAND_ID'] = '1';
        $_ENV['AY_CATEGORY_ID'] = '10';
        $_ENV['AY_COUNTRY_CODES'] = 'DE';
        $_ENV['AY_DESCRIPTION_LOCALE'] = 'en';
        $_ENV['AY_DEFAULT_COLOR_ID'] = '5';
        $_ENV['AY_DEFAULT_SIZE_ID'] = '7';
        $_ENV['AY_DEFAULT_SECOND_SIZE_ID'] = '0';
        $_ENV['AY_DEFAULT_MATERIAL_COMPOSITION_TEXTILE'] = '';
        $_ENV['AY_BRAND_MAP'] = '{}';
        $_ENV['AY_CATEGORY_MAP'] = '{}';
        $_ENV['AY_MATERIAL_COMPONENT_MAP'] = '{}';
        $_ENV['AY_MATERIAL_COMPONENT_NON_TEXTILE_MAP'] = '{}';
        $_ENV['AY_MATERIAL_CLUSTER_MAP'] = '{}';
        $_ENV['AY_DEFAULT_MATERIAL_CLUSTER_ID'] = '1';
        $_ENV['AY_STRICT_PREFLIGHT'] = 'true';
        $_ENV['AY_SKIP_DUPLICATE_TUPLE_VARIANTS'] = 'false';
        $_ENV['AY_MAX_IMAGES'] = '7';
        $_ENV['AY_ALLOW_DESCRIPTION_FALLBACK'] = 'false';
    }

    public function testMapProductToAyRejectsInvalidEan(): void
    {
        $mapper = new AboutYouMapper();

        $this->expectException(ValidationException::class);

        $mapper->mapProductToAy(
            [
                'id' => 100,
                'reference' => 'SKU-100',
                'name' => [['value' => 'Test Product']],
                'description' => [['value' => 'Description']],
                'ean13' => '1234567890123',
                'id_category_default' => 50,
            ],
            [],
            ['https://example.com/image.jpg']
        );
    }

    public function testMapProductToAyBuildsVariantPayload(): void
    {
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 101,
                'reference' => 'SKU-101',
                'name' => [['value' => 'Ready Product']],
                'description' => [['value' => 'A plain text description']],
                'ean13' => '4006381333931',
                'id_category_default' => 99,
                'price' => 12.5,
                'weight' => 0.5,
            ],
            [],
            ['https://example.com/image.jpg']
        );

        self::assertSame('sku-101', $payload['style_key']);
        self::assertCount(1, $payload['variants']);
        self::assertSame('4006381333931', $payload['variants'][0]['ean']);
        self::assertSame(12.5, $payload['variants'][0]['prices'][0]['retail_price']);
    }

    public function testMapProductToAySupportsObjectCategoryAndBrandMapEntries(): void
    {
        $_ENV['AY_BRAND_MAP'] = json_encode(['3' => ['id' => 44]], JSON_THROW_ON_ERROR);
        $_ENV['AY_CATEGORY_MAP'] = json_encode(['9' => ['id' => 99]], JSON_THROW_ON_ERROR);
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 102,
                'reference' => 'SKU-102',
                'id_manufacturer' => 3,
                'id_category_default' => 9,
                'name' => [['value' => 'Map Product']],
                'description' => [['value' => 'Description']],
                'ean13' => '4006381333931',
                'price' => 20,
            ],
            [],
            ['https://example.com/image.jpg']
        );

        self::assertSame(44, $payload['variants'][0]['brand']);
        self::assertSame(99, $payload['variants'][0]['category']);
    }

    public function testMapProductToAyRejectsDuplicateVariantTupleWhenSecondSizeMissing(): void
    {
        $mapper = new AboutYouMapper();
        $this->expectException(ValidationException::class);

        $mapper->mapProductToAy(
            [
                'id' => 103,
                'reference' => 'SKU-103',
                'name' => [['value' => 'Tuple Product']],
                'description' => [['value' => 'Description']],
                'price' => 10,
            ],
            [
                ['id' => 1, 'reference' => 'v1', 'ean13' => '4006381333931', 'quantity' => 2],
                ['id' => 2, 'reference' => 'v2', 'ean13' => '12345670', 'quantity' => 3],
            ],
            ['https://example.com/image.jpg']
        );
    }

    public function testMapProductToAyBuildsStructuredTextileCompositionFromEnvDefault(): void
    {
        $_ENV['AY_DEFAULT_MATERIAL_COMPOSITION_TEXTILE'] = '80% cotton, 20% polyester';
        $_ENV['AY_MATERIAL_COMPONENT_MAP'] = json_encode([
            'cotton' => 1,
            'polyester' => 2,
        ], JSON_THROW_ON_ERROR);
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 104,
                'reference' => 'SKU-104',
                'name' => [['value' => 'Material Product']],
                'description' => [['value' => 'Description']],
                'ean13' => '4006381333931',
                'price' => 15,
                'ay_required_text_fields' => ['material_composition_textile'],
            ],
            [],
            ['https://example.com/image.jpg']
        );

        $composition = $payload['variants'][0]['material_composition_textile'] ?? null;
        self::assertIsArray($composition);
        self::assertSame(1, $composition[0]['cluster_id']);
        $componentIds = array_map(static fn (array $c): int => (int) $c['material_id'], $composition[0]['components']);
        self::assertContains(1, $componentIds);
        self::assertContains(2, $componentIds);
        $totalFraction = array_sum(array_map(static fn (array $c): int => (int) $c['fraction'], $composition[0]['components']));
        self::assertSame(100, $totalFraction);
        self::assertNotEmpty($payload['warnings']);
    }

    public function testMapProductToAyAcceptsPreStructuredTextileComposition(): void
    {
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 110,
                'reference' => 'SKU-110',
                'name' => [['value' => 'Already Structured']],
                'description' => [['value' => 'Description']],
                'ean13' => '4006381333931',
                'price' => 10,
                'material_composition_textile' => [
                    ['cluster_id' => 1, 'components' => [['material_id' => 1, 'fraction' => 100]]],
                ],
            ],
            [],
            ['https://example.com/image.jpg']
        );

        self::assertSame(
            [[ 'cluster_id' => 1, 'components' => [['material_id' => 1, 'fraction' => 100]]]],
            $payload['variants'][0]['material_composition_textile']
        );
    }

    public function testMapProductToAyEmitsStructuredNonTextileComposition(): void
    {
        $_ENV['AY_MATERIAL_COMPONENT_NON_TEXTILE_MAP'] = json_encode([
            'rubber' => 11,
        ], JSON_THROW_ON_ERROR);
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 111,
                'reference' => 'SKU-111',
                'name' => [['value' => 'Non-textile Product']],
                'description' => [['value' => 'Description']],
                'ean13' => '4006381333931',
                'price' => 12,
                'material_composition_non_textile' => '100% rubber',
            ],
            [],
            ['https://example.com/image.jpg']
        );

        $nonTextile = $payload['variants'][0]['material_composition_non_textile'] ?? null;
        self::assertIsArray($nonTextile);
        self::assertSame(11, $nonTextile[0]['components'][0]['material_id']);
    }

    public function testMapProductToAyFlagsMissingRequiredGroups(): void
    {
        $mapper = new AboutYouMapper();

        try {
            $mapper->mapProductToAy(
                [
                    'id' => 105,
                    'reference' => 'SKU-105',
                    'name' => [['value' => 'Strict Required']],
                    'description' => [['value' => 'Description']],
                    'ean13' => '4006381333931',
                    'price' => 10,
                    'ay_required_attribute_groups' => [
                        ['id' => 42, 'name' => 'fitting', 'required' => true],
                    ],
                ],
                [],
                ['https://example.com/image.jpg']
            );
            self::fail('Expected ValidationException for missing required group');
        } catch (ValidationException $e) {
            $joined = implode(' | ', $e->errors());
            self::assertStringContainsString('missing_required_group', $joined);
            self::assertStringContainsString('fitting', $joined);
        }
    }

    public function testMapProductToAySkipsDuplicateTupleWhenWarningModeEnabled(): void
    {
        $_ENV['AY_SKIP_DUPLICATE_TUPLE_VARIANTS'] = 'true';
        $_ENV['AY_ALLOW_MISSING_COMBINATION_ATTRIBUTES'] = 'true';
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 106,
                'reference' => 'SKU-106',
                'name' => [['value' => 'Dup Tolerant']],
                'description' => [['value' => 'Description']],
                'price' => 10,
            ],
            [
                ['id' => 1, 'reference' => 'v1', 'ean13' => '4006381333931', 'quantity' => 2],
                ['id' => 2, 'reference' => 'v2', 'ean13' => '12345670', 'quantity' => 3],
            ],
            ['https://example.com/image.jpg']
        );

        self::assertCount(1, $payload['variants']);
        $warningBlob = implode(' | ', $payload['warnings']);
        self::assertStringContainsString('duplicate_tuple_skipped', $warningBlob);
    }

    public function testMapProductToAyEmitsDefaultFallbackWarningsForColorAndSize(): void
    {
        $_ENV['AY_ALLOW_MISSING_COMBINATION_ATTRIBUTES'] = 'true';
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 107,
                'reference' => 'SKU-107',
                'name' => [['value' => 'Warn Product']],
                'description' => [['value' => 'Description']],
                'ean13' => '4006381333931',
                'price' => 9,
            ],
            [],
            ['https://example.com/image.jpg']
        );

        $warningBlob = implode(' | ', $payload['warnings']);
        self::assertStringContainsString('default_fallback', $warningBlob);
        self::assertStringContainsString('AY_DEFAULT_COLOR_ID', $warningBlob);
        self::assertStringContainsString('AY_DEFAULT_SIZE_ID', $warningBlob);
    }

    public function testMapProductToAyCapsImageCountToConfiguredMaximum(): void
    {
        $_ENV['AY_MAX_IMAGES'] = '2';
        $mapper = new AboutYouMapper();

        $payload = $mapper->mapProductToAy(
            [
                'id' => 120,
                'reference' => 'SKU-120',
                'name' => [['value' => 'Image Capped']],
                'description' => [['value' => 'Description']],
                'ean13' => '4006381333931',
                'price' => 9,
            ],
            [],
            [
                'https://example.com/1.jpg',
                'https://example.com/2.jpg',
                'https://example.com/3.jpg',
            ]
        );

        self::assertCount(2, $payload['variants'][0]['images']);
        self::assertStringContainsString('images_capped', implode(' | ', $payload['warnings']));
    }
}
