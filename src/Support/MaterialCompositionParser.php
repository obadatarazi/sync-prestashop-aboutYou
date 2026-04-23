<?php

declare(strict_types=1);

namespace SyncBridge\Support;

/**
 * Parses free-form material composition strings into normalized parts
 * suitable for AboutYou's structured material_composition_* payload.
 *
 * Supported input shapes:
 *   "100% cotton"
 *   "80% cotton, 20% polyester"
 *   "Shell: 100% cotton; Lining: 95% polyester, 5% elastane"
 *   "Outer fabric 60% wool 40% polyamide"
 *
 * Output: list of clusters:
 *   [
 *     [
 *       'cluster_label' => 'shell' | string | null,
 *       'components' => [
 *         ['label' => 'cotton', 'fraction' => 100],
 *         ...
 *       ],
 *     ],
 *   ]
 *
 * Fractions are integers 1..100. Each cluster's fractions should sum to 100.
 * Pure, DB-free logic so it is cheap to unit test.
 */
final class MaterialCompositionParser
{
    /**
     * Parse a free-form composition string into clusters/components.
     *
     * @return list<array{cluster_label: ?string, components: list<array{label: string, fraction: int}>}>
     */
    public static function parse(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        $input = html_entity_decode($input, ENT_QUOTES | ENT_HTML5);
        $input = str_replace(["\r", "\n"], [' ', ' '], $input);
        $input = preg_replace('/\s+/u', ' ', $input) ?? $input;

        $clusters = [];
        $segments = preg_split('/\s*;\s*/', $input) ?: [$input];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $clusterLabel = null;
            if (preg_match('/^([^:]{1,60}):\s*(.*)$/u', $segment, $match)) {
                $candidate = trim($match[1]);
                if (!preg_match('/\d/', $candidate)) {
                    $clusterLabel = mb_strtolower($candidate);
                    $segment = trim($match[2]);
                }
            }

            $components = self::parseComponents($segment);
            if ($components === []) {
                continue;
            }

            $clusters[] = [
                'cluster_label' => $clusterLabel,
                'components' => $components,
            ];
        }

        return $clusters;
    }

    /**
     * @return list<array{label: string, fraction: int}>
     */
    private static function parseComponents(string $segment): array
    {
        $components = [];
        $pattern = '/(\d{1,3})\s*%\s*([A-Za-z\p{L}][A-Za-z\p{L}\s\-\/]{1,40})/u';
        if (!preg_match_all($pattern, $segment, $matches, PREG_SET_ORDER)) {
            $label = trim($segment);
            if ($label === '') {
                return [];
            }
            $label = mb_strtolower(preg_replace('/\s+/u', ' ', $label) ?? $label);
            return [[
                'label' => self::normalizeLabel($label),
                'fraction' => 100,
            ]];
        }

        foreach ($matches as $match) {
            $fraction = max(0, min(100, (int) $match[1]));
            if ($fraction <= 0) {
                continue;
            }
            $label = self::normalizeLabel((string) $match[2]);
            if ($label === '') {
                continue;
            }
            $components[] = [
                'label' => $label,
                'fraction' => $fraction,
            ];
        }

        return $components;
    }

    private static function normalizeLabel(string $label): string
    {
        $label = trim($label);
        $label = mb_strtolower($label);
        $label = preg_replace('/[^a-z0-9\s\-\/\p{L}]/u', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        $label = trim($label, " -/\t");

        $aliases = [
            'baumwolle' => 'cotton',
            'katoen' => 'cotton',
            'coton' => 'cotton',
            'polyester' => 'polyester',
            'polyamid' => 'polyamide',
            'polyamide' => 'polyamide',
            'polyamida' => 'polyamide',
            'elasthan' => 'elastane',
            'elastan' => 'elastane',
            'elastaan' => 'elastane',
            'wolle' => 'wool',
            'wol' => 'wool',
            'laine' => 'wool',
            'viskose' => 'viscose',
            'viscosa' => 'viscose',
            'seide' => 'silk',
            'soie' => 'silk',
            'leinen' => 'linen',
            'lin' => 'linen',
        ];
        return $aliases[$label] ?? $label;
    }

    /**
     * Basic sanity check that fractions per cluster sum to a valid value.
     * Returns true when every cluster's components sum to 100 (+/- 1 tolerance).
     *
     * @param list<array{cluster_label: ?string, components: list<array{label: string, fraction: int}>}> $clusters
     */
    public static function fractionsAreComplete(array $clusters): bool
    {
        if ($clusters === []) {
            return false;
        }
        foreach ($clusters as $cluster) {
            $sum = 0;
            foreach ($cluster['components'] ?? [] as $component) {
                $sum += (int) ($component['fraction'] ?? 0);
            }
            if ($sum < 99 || $sum > 101) {
                return false;
            }
        }
        return true;
    }
}
