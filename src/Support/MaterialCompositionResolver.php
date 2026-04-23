<?php

declare(strict_types=1);

namespace SyncBridge\Support;

use SyncBridge\Database\Database;

/**
 * Resolves parsed material composition parts (labels + fractions) into the
 * AboutYou-contract-compliant structured payload:
 *
 *   [
 *     { "cluster_id": 1, "components": [{"material_id": 1, "fraction": 100}] }
 *   ]
 *
 * Lookup order per label:
 *   1. Per-product override table `product_material_composition`
 *   2. `material_component_maps` / `material_cluster_maps` database tables
 *   3. JSON env fallbacks (`AY_MATERIAL_COMPONENT_MAP`, `AY_MATERIAL_CLUSTER_MAP`)
 *
 * If any component cannot be resolved, the resolver returns an empty list and
 * emits a structured warning so the caller can short-circuit or log.
 */
final class MaterialCompositionResolver
{
    /**
     * @return array{
     *     structured: list<array{cluster_id:int, components: list<array{material_id:int, fraction:int}>}>,
     *     warnings: list<string>,
     *     unresolved: list<string>,
     * }
     */
    public function resolve(array $clusters, bool $isTextile = true, ?int $productId = null): array
    {
        $structured = [];
        $warnings = [];
        $unresolved = [];

        if ($productId !== null && $productId > 0) {
            $override = $this->loadProductOverride($productId, $isTextile);
            if ($override !== []) {
                return [
                    'structured' => $override,
                    'warnings' => [],
                    'unresolved' => [],
                ];
            }
        }

        foreach ($clusters as $cluster) {
            $clusterLabel = (string) ($cluster['cluster_label'] ?? '');
            $clusterId = $this->resolveClusterId($clusterLabel);
            $components = [];
            foreach (($cluster['components'] ?? []) as $component) {
                $label = (string) ($component['label'] ?? '');
                $fraction = (int) ($component['fraction'] ?? 0);
                if ($fraction <= 0) {
                    continue;
                }
                $materialId = $this->resolveMaterialId($label, $isTextile);
                if ($materialId <= 0) {
                    $unresolved[] = $label;
                    $warnings[] = 'material_composition: no AY material_id mapped for "' . $label . '"';
                    continue;
                }
                $components[] = [
                    'material_id' => $materialId,
                    'fraction' => $fraction,
                ];
            }
            if ($components === []) {
                continue;
            }
            $structured[] = [
                'cluster_id' => $clusterId,
                'components' => $components,
            ];
        }

        if ($unresolved !== []) {
            return [
                'structured' => [],
                'warnings' => array_values(array_unique($warnings)),
                'unresolved' => array_values(array_unique($unresolved)),
            ];
        }

        return [
            'structured' => $structured,
            'warnings' => [],
            'unresolved' => [],
        ];
    }

    private function resolveMaterialId(string $rawLabel, bool $isTextile): int
    {
        $label = $this->normalize($rawLabel);
        if ($label === '') {
            return 0;
        }

        $dbValue = $this->safeFetchValue(
            'SELECT ay_material_id FROM material_component_maps WHERE LOWER(ps_label) = ? AND is_textile = ? LIMIT 1',
            [$label, $isTextile ? 1 : 0]
        );
        if ($dbValue !== null && (int) $dbValue > 0) {
            return (int) $dbValue;
        }

        $envKey = $isTextile ? 'AY_MATERIAL_COMPONENT_MAP' : 'AY_MATERIAL_COMPONENT_NON_TEXTILE_MAP';
        $map = json_decode((string) ($_ENV[$envKey] ?? '{}'), true);
        if (!is_array($map) && $isTextile) {
            $map = [];
        }
        if (is_array($map)) {
            foreach ($map as $key => $value) {
                if (strcasecmp((string) $key, $label) === 0) {
                    $resolved = is_array($value) ? ($value['id'] ?? 0) : $value;
                    return (int) $resolved;
                }
            }
        }

        if ($isTextile) {
            $fallback = json_decode((string) ($_ENV['AY_MATERIAL_COMPONENT_NON_TEXTILE_MAP'] ?? '{}'), true);
            if (is_array($fallback)) {
                foreach ($fallback as $key => $value) {
                    if (strcasecmp((string) $key, $label) === 0) {
                        $resolved = is_array($value) ? ($value['id'] ?? 0) : $value;
                        return (int) $resolved;
                    }
                }
            }
        }

        return 0;
    }

    private function resolveClusterId(string $rawLabel): int
    {
        $default = max(1, (int) ($_ENV['AY_DEFAULT_MATERIAL_CLUSTER_ID'] ?? 1));
        $label = $this->normalize($rawLabel);
        if ($label === '') {
            return $default;
        }

        $dbValue = $this->safeFetchValue(
            'SELECT ay_cluster_id FROM material_cluster_maps WHERE LOWER(ps_label) = ? LIMIT 1',
            [$label]
        );
        if ($dbValue !== null && (int) $dbValue > 0) {
            return (int) $dbValue;
        }

        $map = json_decode((string) ($_ENV['AY_MATERIAL_CLUSTER_MAP'] ?? '{}'), true);
        if (is_array($map)) {
            foreach ($map as $key => $value) {
                if (strcasecmp((string) $key, $label) === 0) {
                    $resolved = is_array($value) ? ($value['id'] ?? 0) : $value;
                    if ((int) $resolved > 0) {
                        return (int) $resolved;
                    }
                }
            }
        }

        return $default;
    }

    /**
     * @return list<array{cluster_id:int, components: list<array{material_id:int, fraction:int}>}>
     */
    private function loadProductOverride(int $productId, bool $isTextile): array
    {
        $rows = $this->safeFetchAll(
            'SELECT cluster_id, ay_material_id, fraction FROM product_material_composition
             WHERE product_id = ? AND is_textile = ? ORDER BY cluster_id, id',
            [$productId, $isTextile ? 1 : 0]
        );

        if ($rows === []) {
            return [];
        }

        $clusters = [];
        foreach ($rows as $row) {
            $clusterId = max(1, (int) ($row['cluster_id'] ?? 1));
            $materialId = (int) ($row['ay_material_id'] ?? 0);
            $fraction = (int) ($row['fraction'] ?? 0);
            if ($materialId <= 0 || $fraction <= 0) {
                continue;
            }
            $clusters[$clusterId][] = [
                'material_id' => $materialId,
                'fraction' => $fraction,
            ];
        }

        $result = [];
        foreach ($clusters as $clusterId => $components) {
            $result[] = [
                'cluster_id' => (int) $clusterId,
                'components' => $components,
            ];
        }
        return $result;
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return $value;
    }

    /**
     * Wraps Database::fetchValue so missing DB (tests) does not abort the flow.
     */
    private function safeFetchValue(string $sql, array $params): mixed
    {
        try {
            return Database::fetchValue($sql, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeFetchAll(string $sql, array $params): array
    {
        try {
            return Database::fetchAll($sql, $params);
        } catch (\Throwable) {
            return [];
        }
    }
}
