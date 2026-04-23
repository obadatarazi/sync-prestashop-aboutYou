<?php

declare(strict_types=1);

namespace SyncBridge\Support;

/**
 * Strict local preflight validator for AboutYou product payloads.
 *
 * Asserts structural invariants of the AY /products contract so that we stop
 * avoidable API rejections:
 *   - required AY attribute groups present
 *   - required text fields non-empty
 *   - structured material composition shape + fraction sums
 *   - basic price / image / country invariants
 *
 * All failures are classified with a reason code so downstream logs and
 * retries can decide whether the error is retryable (transport) or
 * non-retryable (contract violation).
 */
final class AyPayloadValidator
{
    public const REASON_MISSING_REQUIRED_GROUP   = 'missing_required_group';
    public const REASON_MISSING_REQUIRED_TEXT    = 'missing_required_text';
    public const REASON_INVALID_MATERIAL_SHAPE   = 'invalid_material_shape';
    public const REASON_INVALID_MATERIAL_FRACTION = 'invalid_material_fraction';
    public const REASON_INVALID_PRICE            = 'invalid_price';
    public const REASON_MISSING_IMAGES           = 'missing_images';
    public const REASON_MISSING_COUNTRIES        = 'missing_countries';
    public const REASON_INVALID_STYLE_KEY        = 'invalid_style_key';
    public const REASON_INVALID_COLOR            = 'invalid_color';
    public const REASON_INVALID_SIZE             = 'invalid_size';
    public const REASON_TOO_MANY_IMAGES          = 'too_many_images';

    /**
     * @param array{
     *     style_key?: string,
     *     variants?: list<array<string, mixed>>,
     *     warnings?: list<string>,
     * } $ayProduct
     * @param array{
     *     required_groups?: list<array{id:int,name?:string}>,
     *     required_text_fields?: list<string>,
     *     strict?: bool,
     *     max_images?: int,
     * } $context
     *
     * @return array{
     *     errors: list<array{reason:string, message:string, sku?:string}>,
     *     warnings: list<string>,
     * }
     */
    public function validate(array $ayProduct, array $context = []): array
    {
        $errors = [];
        $warnings = $ayProduct['warnings'] ?? [];

        $styleKey = trim((string) ($ayProduct['style_key'] ?? ''));
        if ($styleKey === '') {
            $errors[] = [
                'reason' => self::REASON_INVALID_STYLE_KEY,
                'message' => 'AY payload is missing a style_key',
            ];
        }

        $variants = $ayProduct['variants'] ?? [];
        if (!is_array($variants) || $variants === []) {
            $errors[] = [
                'reason' => self::REASON_MISSING_REQUIRED_GROUP,
                'message' => 'AY payload has no variants',
            ];
            return ['errors' => $errors, 'warnings' => array_values($warnings)];
        }

        $requiredGroups = array_values(array_filter(
            (array) ($context['required_groups'] ?? []),
            static fn (mixed $g): bool => is_array($g) && (int) ($g['id'] ?? 0) > 0
        ));
        $requiredTextFields = array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): string => strtolower(trim((string) $v)),
            (array) ($context['required_text_fields'] ?? [])
        ))));

        $maxImages = max(1, (int) ($context['max_images'] ?? 7));
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $sku = (string) ($variant['sku'] ?? 'unknown');

            if (((int) ($variant['color'] ?? 0)) <= 0) {
                $errors[] = [
                    'reason' => self::REASON_INVALID_COLOR,
                    'message' => 'Variant ' . $sku . ' has no AY color id',
                    'sku' => $sku,
                ];
            }
            if (((int) ($variant['size'] ?? 0)) <= 0) {
                $errors[] = [
                    'reason' => self::REASON_INVALID_SIZE,
                    'message' => 'Variant ' . $sku . ' has no AY size id',
                    'sku' => $sku,
                ];
            }

            $resolvedGroupIds = array_map('intval', (array) ($variant['_resolved_attribute_groups'] ?? []));
            foreach ($requiredGroups as $group) {
                $groupId = (int) ($group['id'] ?? 0);
                if ($groupId <= 0 || in_array($groupId, $resolvedGroupIds, true)) {
                    continue;
                }
                $errors[] = [
                    'reason' => self::REASON_MISSING_REQUIRED_GROUP,
                    'message' => 'Variant ' . $sku . ' is missing required attribute group '
                        . (string) ($group['name'] ?? 'group_' . $groupId) . ' (id ' . $groupId . ')',
                    'sku' => $sku,
                ];
            }

            foreach ($requiredTextFields as $fieldName) {
                $value = $variant[$fieldName] ?? null;
                if ($this->isStructuredMaterialField($fieldName)) {
                    if (!$this->isValidStructuredComposition($value)) {
                        $errors[] = [
                            'reason' => self::REASON_INVALID_MATERIAL_SHAPE,
                            'message' => 'Variant ' . $sku . ' has missing or invalid ' . $fieldName,
                            'sku' => $sku,
                        ];
                    } elseif (!$this->fractionsSumCorrectly($value)) {
                        $errors[] = [
                            'reason' => self::REASON_INVALID_MATERIAL_FRACTION,
                            'message' => 'Variant ' . $sku . ' ' . $fieldName . ' fractions do not sum to 100',
                            'sku' => $sku,
                        ];
                    }
                    continue;
                }

                if (is_array($value)) {
                    if ($value === []) {
                        $errors[] = [
                            'reason' => self::REASON_MISSING_REQUIRED_TEXT,
                            'message' => 'Variant ' . $sku . ' is missing required field ' . $fieldName,
                            'sku' => $sku,
                        ];
                    }
                    continue;
                }
                if (trim((string) $value) === '') {
                    $errors[] = [
                        'reason' => self::REASON_MISSING_REQUIRED_TEXT,
                        'message' => 'Variant ' . $sku . ' is missing required field ' . $fieldName,
                        'sku' => $sku,
                    ];
                }
            }

            foreach (['material_composition_textile', 'material_composition_non_textile'] as $key) {
                if (!array_key_exists($key, $variant)) {
                    continue;
                }
                $value = $variant[$key];
                if (!$this->isValidStructuredComposition($value)) {
                    $errors[] = [
                        'reason' => self::REASON_INVALID_MATERIAL_SHAPE,
                        'message' => 'Variant ' . $sku . ' has invalid ' . $key . ' shape; expected [{cluster_id, components:[{material_id, fraction}]}]',
                        'sku' => $sku,
                    ];
                    continue;
                }
                if ($key === 'material_composition_textile' && !$this->fractionsSumCorrectly($value)) {
                    $errors[] = [
                        'reason' => self::REASON_INVALID_MATERIAL_FRACTION,
                        'message' => 'Variant ' . $sku . ' material_composition_textile fractions do not sum to 100',
                        'sku' => $sku,
                    ];
                }
            }

            $prices = $variant['prices'] ?? [];
            if (!is_array($prices) || $prices === []) {
                $errors[] = [
                    'reason' => self::REASON_INVALID_PRICE,
                    'message' => 'Variant ' . $sku . ' has no prices',
                    'sku' => $sku,
                ];
            } else {
                foreach ($prices as $price) {
                    $retail = (float) ($price['retail_price'] ?? 0);
                    if ($retail <= 0) {
                        $errors[] = [
                            'reason' => self::REASON_INVALID_PRICE,
                            'message' => 'Variant ' . $sku . ' retail_price must be > 0',
                            'sku' => $sku,
                        ];
                        break;
                    }
                }
            }

            $countries = $variant['countries'] ?? [];
            if (!is_array($countries) || $countries === []) {
                $errors[] = [
                    'reason' => self::REASON_MISSING_COUNTRIES,
                    'message' => 'Variant ' . $sku . ' has no target countries',
                    'sku' => $sku,
                ];
            }

            $images = $variant['images'] ?? [];
            if (!is_array($images) || $images === []) {
                $errors[] = [
                    'reason' => self::REASON_MISSING_IMAGES,
                    'message' => 'Variant ' . $sku . ' has no images',
                    'sku' => $sku,
                ];
            } elseif (count($images) > $maxImages) {
                $errors[] = [
                    'reason' => self::REASON_TOO_MANY_IMAGES,
                    'message' => 'Variant ' . $sku . ' has too many images (' . count($images) . ' > ' . $maxImages . ')',
                    'sku' => $sku,
                ];
            }
        }

        return [
            'errors' => $errors,
            'warnings' => array_values(array_filter(array_map(
                static fn (mixed $w): string => (string) $w,
                (array) $warnings
            ))),
        ];
    }

    private function isStructuredMaterialField(string $field): bool
    {
        return in_array($field, ['material_composition_textile', 'material_composition_non_textile'], true);
    }

    private function isValidStructuredComposition(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $cluster) {
            if (!is_array($cluster)) {
                return false;
            }
            if (!isset($cluster['cluster_id']) || (int) $cluster['cluster_id'] <= 0) {
                return false;
            }
            $components = $cluster['components'] ?? null;
            if (!is_array($components) || $components === []) {
                return false;
            }
            foreach ($components as $component) {
                if (!is_array($component)) {
                    return false;
                }
                if (!isset($component['material_id']) || (int) $component['material_id'] <= 0) {
                    return false;
                }
                if (array_key_exists('fraction', $component)) {
                    $fraction = (int) $component['fraction'];
                    if ($fraction < 0 || $fraction > 100) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function fractionsSumCorrectly(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $cluster) {
            $sum = 0;
            foreach (($cluster['components'] ?? []) as $component) {
                $sum += (int) ($component['fraction'] ?? 0);
            }
            if ($sum < 99 || $sum > 101) {
                return false;
            }
        }
        return true;
    }
}
