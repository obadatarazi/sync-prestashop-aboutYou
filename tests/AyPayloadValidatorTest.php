<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SyncBridge\Support\AyPayloadValidator;

final class AyPayloadValidatorTest extends TestCase
{
    private function validVariant(array $overrides = []): array
    {
        return array_replace([
            'sku' => 'sku-1',
            'color' => 1,
            'size' => 2,
            'material_composition_textile' => [
                ['cluster_id' => 1, 'components' => [['material_id' => 1, 'fraction' => 100]]],
            ],
            'prices' => [['country_code' => 'DE', 'retail_price' => 10]],
            'countries' => ['DE'],
            'images' => ['https://example.com/a.jpg'],
            '_resolved_attribute_groups' => [],
        ], $overrides);
    }

    public function testValidatesHappyPathWithoutErrors(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate([
            'style_key' => 'sku-1',
            'variants' => [$this->validVariant()],
            'warnings' => [],
        ]);

        self::assertSame([], $result['errors']);
    }

    public function testRejectsInvalidMaterialShape(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate([
            'style_key' => 'sku-1',
            'variants' => [
                $this->validVariant(['material_composition_textile' => 'bad']),
            ],
        ]);

        $reasons = array_column($result['errors'], 'reason');
        self::assertContains(AyPayloadValidator::REASON_INVALID_MATERIAL_SHAPE, $reasons);
    }

    public function testRejectsMaterialFractionNotSumming(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate([
            'style_key' => 'sku-1',
            'variants' => [
                $this->validVariant([
                    'material_composition_textile' => [
                        ['cluster_id' => 1, 'components' => [['material_id' => 1, 'fraction' => 50]]],
                    ],
                ]),
            ],
        ]);

        $reasons = array_column($result['errors'], 'reason');
        self::assertContains(AyPayloadValidator::REASON_INVALID_MATERIAL_FRACTION, $reasons);
    }

    public function testRejectsMissingRequiredGroup(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate([
            'style_key' => 'sku-1',
            'variants' => [$this->validVariant()],
        ], [
            'required_groups' => [['id' => 99, 'name' => 'fitting']],
        ]);

        $reasons = array_column($result['errors'], 'reason');
        self::assertContains(AyPayloadValidator::REASON_MISSING_REQUIRED_GROUP, $reasons);
    }

    public function testRejectsMissingPriceOrImages(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate([
            'style_key' => 'sku-1',
            'variants' => [$this->validVariant(['prices' => [], 'images' => []])],
        ]);

        $reasons = array_column($result['errors'], 'reason');
        self::assertContains(AyPayloadValidator::REASON_INVALID_PRICE, $reasons);
        self::assertContains(AyPayloadValidator::REASON_MISSING_IMAGES, $reasons);
    }

    public function testRejectsPayloadWithoutVariants(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate(['style_key' => 'sku-1', 'variants' => []]);

        self::assertNotEmpty($result['errors']);
    }

    public function testRejectsEmptyStyleKey(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate(['style_key' => '', 'variants' => [$this->validVariant()]]);

        $reasons = array_column($result['errors'], 'reason');
        self::assertContains(AyPayloadValidator::REASON_INVALID_STYLE_KEY, $reasons);
    }

    public function testRejectsTooManyImagesWhenLimitExceeded(): void
    {
        $validator = new AyPayloadValidator();
        $result = $validator->validate([
            'style_key' => 'sku-1',
            'variants' => [
                $this->validVariant([
                    'images' => [
                        'https://example.com/1.jpg',
                        'https://example.com/2.jpg',
                        'https://example.com/3.jpg',
                    ],
                ]),
            ],
        ], [
            'max_images' => 2,
        ]);

        $reasons = array_column($result['errors'], 'reason');
        self::assertContains(AyPayloadValidator::REASON_TOO_MANY_IMAGES, $reasons);
    }
}
