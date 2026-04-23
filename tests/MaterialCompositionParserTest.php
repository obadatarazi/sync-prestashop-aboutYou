<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SyncBridge\Support\MaterialCompositionParser;

final class MaterialCompositionParserTest extends TestCase
{
    public function testParsesSingleComponent(): void
    {
        $clusters = MaterialCompositionParser::parse('100% cotton');

        self::assertCount(1, $clusters);
        self::assertNull($clusters[0]['cluster_label']);
        self::assertSame([[ 'label' => 'cotton', 'fraction' => 100 ]], $clusters[0]['components']);
        self::assertTrue(MaterialCompositionParser::fractionsAreComplete($clusters));
    }

    public function testParsesMultipleComponentsInOneCluster(): void
    {
        $clusters = MaterialCompositionParser::parse('80% cotton, 20% polyester');

        self::assertCount(1, $clusters);
        self::assertCount(2, $clusters[0]['components']);
        self::assertTrue(MaterialCompositionParser::fractionsAreComplete($clusters));
    }

    public function testParsesNamedClusters(): void
    {
        $clusters = MaterialCompositionParser::parse(
            'Shell: 100% cotton; Lining: 95% polyester, 5% elastane'
        );

        self::assertCount(2, $clusters);
        self::assertSame('shell', $clusters[0]['cluster_label']);
        self::assertSame('lining', $clusters[1]['cluster_label']);
        self::assertTrue(MaterialCompositionParser::fractionsAreComplete($clusters));
    }

    public function testLocalizedAliasesAreNormalized(): void
    {
        $clusters = MaterialCompositionParser::parse('60% Baumwolle, 40% Polyamid');

        $labels = array_map(static fn (array $c): string => $c['label'], $clusters[0]['components']);
        self::assertContains('cotton', $labels);
        self::assertContains('polyamide', $labels);
    }

    public function testReturnsEmptyArrayForBlankInput(): void
    {
        self::assertSame([], MaterialCompositionParser::parse(''));
        self::assertSame([], MaterialCompositionParser::parse('   '));
    }

    public function testFractionCompleteReturnsFalseWhenSumOff(): void
    {
        $clusters = MaterialCompositionParser::parse('50% cotton');
        self::assertFalse(MaterialCompositionParser::fractionsAreComplete($clusters));
    }
}
