<?php

declare(strict_types=1);

namespace SyncBridge\Integration;

use SyncBridge\Database\Database;
use SyncBridge\Support\AttributeTypeGuesser;
use SyncBridge\Support\AyPayloadValidator;
use SyncBridge\Support\MaterialCompositionParser;
use SyncBridge\Support\MaterialCompositionResolver;
use SyncBridge\Support\ValidationException;

final class AboutYouMapper
{
    private MaterialCompositionResolver $materialResolver;
    private AyPayloadValidator $validator;

    public function __construct(
        ?MaterialCompositionResolver $materialResolver = null,
        ?AyPayloadValidator $validator = null
    ) {
        $this->materialResolver = $materialResolver ?? new MaterialCompositionResolver();
        $this->validator = $validator ?? new AyPayloadValidator();
    }

    public function mapProductToAy(array $psProduct, array $combinations, array $imageUrls, string $categoryName = ''): array
    {
        $title = trim((string) ($psProduct['export_title'] ?? ''));
        if ($title === '') {
            $title = $this->extractLocalizedValue($psProduct['name'] ?? '');
        }
        $description = trim((string) ($psProduct['export_description'] ?? ''));
        if ($description === '') {
            $description = $this->extractLocalizedValue($psProduct['description'] ?? '');
        }
        $usedDescriptionFallback = false;
        if ($description === '' && filter_var($_ENV['AY_ALLOW_DESCRIPTION_FALLBACK'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $description = trim((string) ($psProduct['description_short'] ?? $title));
            $usedDescriptionFallback = ($description !== '');
        }
        $description = $this->sanitizeDescription($description);
        $reference = trim((string) ($psProduct['reference'] ?? ''));
        $styleKey = $this->buildStyleKey($psProduct, $reference);
        $brandId = $this->resolveBrandId($psProduct);
        $categoryId = $this->resolveCategoryId($psProduct);

        $errors = [];
        $warnings = [];
        if ($usedDescriptionFallback) {
            $warnings[] = '[reason=description_fallback] Description was empty; used description_short/title fallback';
        }
        $requiredGroups = $this->resolveRequiredAttributeGroups($psProduct);
        $requiredTextFields = $this->resolveRequiredTextFields($psProduct);
        if ($title === '') {
            $errors[] = '[reason=missing_required_text] Missing title';
        }
        if ($description === '') {
            $errors[] = '[reason=missing_required_text] Missing description';
        }
        if ($brandId <= 0) {
            $errors[] = '[reason=missing_required_group] Missing AboutYou brand mapping';
        }
        if ($categoryId <= 0) {
            $errors[] = '[reason=missing_required_group] Missing AboutYou category mapping';
        }
        if (count($imageUrls) === 0) {
            $errors[] = '[reason=missing_images] At least one product image is required';
        }

        $variants = [];
        $seenEans = [];
        $seenVariantTuples = [];
        $sourceVariants = $combinations !== [] ? $combinations : [$psProduct + ['id' => 0, 'quantity' => $psProduct['quantity'] ?? 0]];
        $allVariantAttributesMissing = count($sourceVariants) > 1;
        foreach ($sourceVariants as $variant) {
            if (!empty($variant['attributes']) && is_array($variant['attributes'])) {
                $allVariantAttributesMissing = false;
                break;
            }
        }
        $allowMissingCombinationAttributes = filter_var($_ENV['AY_ALLOW_MISSING_COMBINATION_ATTRIBUTES'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($allVariantAttributesMissing) {
            $message = 'PrestaShop combinations are missing option attributes for this product; AY cannot build unique variant tuples.';
            if ($allowMissingCombinationAttributes) {
                $warnings[] = $message . ' [allowed due to AY_ALLOW_MISSING_COMBINATION_ATTRIBUTES=true]';
            } else {
                $errors[] = $message;
            }
        }
        $allowDuplicateTupleSkip = filter_var($_ENV['AY_SKIP_DUPLICATE_TUPLE_VARIANTS'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $strictPreflight = filter_var($_ENV['AY_STRICT_PREFLIGHT'] ?? true, FILTER_VALIDATE_BOOLEAN);

        foreach ($sourceVariants as $variant) {
            $mapped = $this->mapVariant($psProduct, $variant, $styleKey, $title, $description, $brandId, $categoryId, $imageUrls);
            $colorHint = $this->describeVariantAttribute($variant, 'color');
            $sizeHint = $this->describeVariantAttribute($variant, 'size');
            if (((int) ($mapped['color'] ?? 0)) <= 0) {
                $errors[] = '[reason=invalid_color] Variant ' . ($mapped['sku'] ?? 'unknown') . ' is missing AboutYou color mapping' . ($colorHint !== '' ? ' (' . $colorHint . ')' : '');
                continue;
            }
            if (((int) ($mapped['size'] ?? 0)) <= 0) {
                $errors[] = '[reason=invalid_size] Variant ' . ($mapped['sku'] ?? 'unknown') . ' is missing AboutYou size mapping' . ($sizeHint !== '' ? ' (' . $sizeHint . ')' : '');
                continue;
            }
            $ean = (string) ($mapped['ean'] ?? '');
            if ($ean === '') {
                $errors[] = '[reason=missing_ean] Variant ' . ($mapped['sku'] ?? 'unknown') . ' is missing EAN';
                continue;
            }
            if (!$this->isValidGtin($ean)) {
                $errors[] = '[reason=invalid_ean] Variant ' . ($mapped['sku'] ?? 'unknown') . ' has invalid EAN/GTIN';
                continue;
            }
            if (isset($seenEans[$ean])) {
                $errors[] = '[reason=duplicate_ean] Duplicate EAN detected in product payload: ' . $ean;
                continue;
            }
            $tupleKey = implode('|', [
                $mapped['style_key'] ?? '',
                (string) ($mapped['color'] ?? ''),
                (string) ($mapped['size'] ?? ''),
                (string) ($mapped['second_size'] ?? 'None'),
            ]);
            if (isset($seenVariantTuples[$tupleKey])) {
                $first = $seenVariantTuples[$tupleKey];
                $duplicateError = "Duplicate variant combination (style_key='" . ($mapped['style_key'] ?? '')
                    . "', color=" . (string) ($mapped['color'] ?? '')
                    . ", size=" . (string) ($mapped['size'] ?? '')
                    . ", second_size=" . (string) ($mapped['second_size'] ?? 'None')
                    . ") in payload (first seen at SKU " . ($first['sku'] ?? 'unknown')
                    . ', duplicate SKU ' . ($mapped['sku'] ?? 'unknown')
                    . ', first raw=' . ($first['raw'] ?? 'n/a')
                    . ', duplicate raw=' . $this->describeVariantAttribute($variant, 'second_size') . ')';
                if ($allowDuplicateTupleSkip) {
                    $warnings[] = '[reason=duplicate_tuple_skipped] ' . $duplicateError;
                    continue;
                }
                $errors[] = '[reason=duplicate_tuple] ' . $duplicateError;
                continue;
            }
            $seenVariantTuples[$tupleKey] = [
                'sku' => (string) ($mapped['sku'] ?? 'unknown'),
                'raw' => $this->describeVariantAttribute($variant, 'second_size'),
            ];
            $seenEans[$ean] = true;
            foreach (($mapped['_warnings'] ?? []) as $warning) {
                $warnings[] = (string) $warning;
            }
            $variants[] = $mapped;
        }

        if ($variants === []) {
            $errors[] = '[reason=no_valid_variants] No valid variants available for export';
        }

        $preliminary = [
            'style_key' => $styleKey,
            'variants' => $variants,
            'warnings' => $warnings,
        ];

        if ($errors === []) {
            $validation = $this->validator->validate($preliminary, [
                'required_groups' => $requiredGroups,
                'required_text_fields' => $requiredTextFields,
                'strict' => $strictPreflight,
                'max_images' => max(1, (int) ($_ENV['AY_MAX_IMAGES'] ?? 7)),
            ]);
            foreach ($validation['errors'] as $err) {
                $reason = (string) ($err['reason'] ?? 'validation');
                $errors[] = '[reason=' . $reason . '] ' . (string) ($err['message'] ?? 'validation error');
            }
            foreach ($validation['warnings'] as $w) {
                $warnings[] = (string) $w;
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Product validation failed', $errors);
        }

        foreach ($variants as &$variantPayload) {
            unset($variantPayload['_resolved_attribute_groups']);
            unset($variantPayload['_warnings']);
        }
        unset($variantPayload);

        return [
            'style_key' => $styleKey,
            'variants' => $variants,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    public function mapAyOrderToPs(array $ayOrder): array
    {
        $items = [];
        foreach (($ayOrder['order_items'] ?? []) as $item) {
            $price = (float) ($item['price_with_tax'] ?? $item['price'] ?? 0);
            $items[] = [
                'ay_order_item_id' => (int) ($item['id'] ?? 0),
                'sku' => trim((string) ($item['sku'] ?? '')),
                'ean13' => trim((string) ($item['ean'] ?? $item['ean13'] ?? '')),
                'quantity' => 1,
                'unit_price' => $price,
                'status' => (string) ($item['status'] ?? 'open'),
            ];
        }

        $customer = [
            'email' => (string) ($ayOrder['customer_email'] ?? (($ayOrder['customer_key'] ?? '') !== '' ? $ayOrder['customer_key'] . '@aboutyou.local' : '')),
            'first_name' => (string) ($ayOrder['billing_recipient_first_name'] ?? $ayOrder['shipping_recipient_first_name'] ?? 'AboutYou'),
            'last_name' => (string) ($ayOrder['billing_recipient_last_name'] ?? $ayOrder['shipping_recipient_last_name'] ?? 'Customer'),
        ];

        $address = [
            'alias' => 'AboutYou ' . (string) ($ayOrder['order_number'] ?? $ayOrder['id'] ?? 'Order'),
            'first_name' => (string) ($ayOrder['shipping_recipient_first_name'] ?? $customer['first_name']),
            'last_name' => (string) ($ayOrder['shipping_recipient_last_name'] ?? $customer['last_name']),
            'address1' => (string) ($ayOrder['shipping_street'] ?? ''),
            'address2' => (string) ($ayOrder['shipping_additional'] ?? ''),
            'postcode' => (string) ($ayOrder['shipping_zip_code'] ?? ''),
            'city' => (string) ($ayOrder['shipping_city'] ?? ''),
            'country_iso' => (string) ($ayOrder['shipping_country_code'] ?? 'DE'),
            'phone' => (string) ($ayOrder['shipping_phone_number'] ?? ''),
        ];

        return [
            'customer' => $customer,
            'address' => $address,
            'items' => $items,
            'currency' => (string) ($ayOrder['currency'] ?? 'EUR'),
            'total_products' => (float) ($ayOrder['cost_without_tax'] ?? 0),
            'total_shipping' => 0.0,
            'total_paid' => (float) ($ayOrder['cost_with_tax'] ?? 0),
            'external_reference' => (string) ($ayOrder['order_number'] ?? $ayOrder['id'] ?? ''),
        ];
    }

    public function mapPsStatusToAy(int|array $psState): string
    {
        $statusName = is_array($psState)
            ? strtolower(trim((string) ($psState['current_state_name'] ?? '')))
            : '';
        $stateId = is_array($psState) ? (int) ($psState['current_state'] ?? 0) : (int) $psState;

        $jsonMap = (string) ($_ENV['PS_TO_AY_STATUS_MAP'] ?? '');
        if ($jsonMap !== '') {
            $parsed = json_decode($jsonMap, true);
            if (is_array($parsed)) {
                if ($statusName !== '' && isset($parsed[$statusName])) {
                    return (string) $parsed[$statusName];
                }
                if (isset($parsed[(string) $stateId])) {
                    return (string) $parsed[(string) $stateId];
                }
            }
        }

        if ($statusName !== '') {
            if (str_contains($statusName, 'ship')) {
                return 'shipped';
            }
            if (str_contains($statusName, 'cancel')) {
                return 'cancelled';
            }
            if (str_contains($statusName, 'return')) {
                return 'returned';
            }
        }

        return match ($stateId) {
            4 => 'shipped',
            6 => 'cancelled',
            7, 8 => 'returned',
            default => 'processing',
        };
    }

    private function mapVariant(
        array $psProduct,
        array $variant,
        string $styleKey,
        string $title,
        string $description,
        int $brandId,
        int $categoryId,
        array $imageUrls
    ): array {
        $sku = trim((string) ($variant['reference'] ?? $variant['sku'] ?? $psProduct['reference'] ?? ''));
        if ($sku === '') {
            $sku = $styleKey . '-' . (string) ($variant['id'] ?? $variant['ps_combo_id'] ?? 'base');
        }

        $ean = trim((string) ($variant['ean13'] ?? $psProduct['ean13'] ?? ''));
        $quantity = max(0, (int) ($variant['quantity'] ?? 0));
        $price = $this->calculateVariantRetailPrice($psProduct, $variant);
        $warnings = [];
        $colorResolution = $this->resolveAttributeIdWithDiagnostics($variant, 'color');
        if ($colorResolution['used_default']) {
            $warnings[] = '[reason=default_fallback] Variant ' . $sku . ' color resolved via AY_DEFAULT_COLOR_ID; add an attribute_maps entry to avoid duplicate-tuple collisions.';
        }
        $sizeResolution = $this->resolveAttributeIdWithDiagnostics($variant, 'size');
        if ($sizeResolution['used_default']) {
            $warnings[] = '[reason=default_fallback] Variant ' . $sku . ' size resolved via AY_DEFAULT_SIZE_ID; add an attribute_maps entry to avoid duplicate-tuple collisions.';
        }
        $secondSizeResolution = $this->resolveAttributeIdWithDiagnostics($variant, 'second_size');
        $colorId = $colorResolution['id'];
        $sizeId = $sizeResolution['id'];
        $secondSizeId = $secondSizeResolution['id'];
        $attributeResolution = $this->resolveAttributes($variant);
        $requiredDefaults = $this->resolveRequiredAttributeDefaults($psProduct);
        foreach ($requiredDefaults['warnings'] as $requiredWarning) {
            $warnings[] = '[reason=default_fallback] Variant ' . $sku . ' ' . $requiredWarning;
        }
        $attributes = array_values(array_unique(array_filter(array_merge(
            $attributeResolution['ids'],
            $requiredDefaults['ids']
        ), static fn (mixed $id): bool => (int) $id > 0)));
        $resolvedGroupIds = array_values(array_unique(array_merge(
            $attributeResolution['group_ids'],
            $requiredDefaults['group_ids']
        )));
        $countryCodes = $this->resolveCountryCodes($psProduct);
        $maxImages = max(1, (int) ($_ENV['AY_MAX_IMAGES'] ?? 7));
        $limitedImages = array_slice(array_values($imageUrls), 0, $maxImages);
        if (count($imageUrls) > $maxImages) {
            $warnings[] = '[reason=images_capped] Variant ' . $sku . ' images capped to ' . $maxImages . ' (from ' . count($imageUrls) . ')';
        }
        $weightGrams = max(1, (int) round(((float) ($variant['weight'] ?? $psProduct['weight'] ?? 0.1)) * 1000));
        $textileInfo = $this->resolveStructuredMaterialComposition($psProduct, $variant, true);
        $nonTextileInfo = $this->resolveStructuredMaterialComposition($psProduct, $variant, false);
        foreach ($textileInfo['warnings'] as $warning) {
            $warnings[] = '[reason=material_fallback] Variant ' . $sku . ' ' . $warning;
        }
        foreach ($nonTextileInfo['warnings'] as $warning) {
            $warnings[] = '[reason=material_fallback] Variant ' . $sku . ' ' . $warning;
        }

        $payload = [
            'style_key' => $styleKey,
            'sku' => $sku,
            'ean' => $ean,
            'name' => $title,
            'descriptions' => [
                (string) ($_ENV['AY_DESCRIPTION_LOCALE'] ?? 'en') => $description,
            ],
            'brand' => $brandId,
            'category' => $categoryId,
            'color' => $colorId,
            'size' => $sizeId,
            'second_size' => $secondSizeId > 0 ? $secondSizeId : null,
            'quantity' => $quantity,
            'weight' => $weightGrams,
            'countries' => $countryCodes,
            'country_of_origin' => strtoupper((string) ($psProduct['country_of_origin'] ?? $_ENV['AY_DEFAULT_COUNTRY_OF_ORIGIN'] ?? 'DE')),
            'attributes' => $attributes,
            '_resolved_attribute_groups' => $resolvedGroupIds,
            'prices' => array_map(static fn (string $countryCode): array => [
                'country_code' => $countryCode,
                'retail_price' => round($price, 2),
            ], $countryCodes),
            'images' => $limitedImages,
            '_warnings' => $warnings,
        ];
        if ($textileInfo['structured'] !== []) {
            $payload['material_composition_textile'] = $textileInfo['structured'];
        }
        if ($nonTextileInfo['structured'] !== []) {
            $payload['material_composition_non_textile'] = $nonTextileInfo['structured'];
        }
        return $payload;
    }

    public function calculateVariantRetailPrice(array $psProduct, array $variant): float
    {
        $basePrice = (float) ($psProduct['price'] ?? 0);
        $modifier = (float) ($variant['price_modifier'] ?? 0);
        if ($modifier === 0.0 && isset($variant['id']) && (int) $variant['id'] !== (int) ($psProduct['id'] ?? 0)) {
            $modifier = (float) ($variant['price'] ?? 0);
        }
        return $basePrice + $modifier;
    }

    private function resolveAttributes(array $variant): array
    {
        $attributes = ['ids' => [], 'group_ids' => []];
        foreach (($variant['attributes'] ?? []) as $attribute) {
            $valueName = trim((string) ($attribute['value_name'] ?? ''));
            if ($valueName === '') {
                continue;
            }
            $groupId = (int) ($attribute['group_id'] ?? 0);
            $mapped = null;
            if ($groupId > 0) {
                $mapped = $this->safeFetchOne(
                    'SELECT ay_id, ay_group_id FROM attribute_maps
                     WHERE map_type IN (?, ?) AND LOWER(ps_label) = LOWER(?) AND ay_group_id = ?
                     LIMIT 1',
                    ['attribute', 'attribute_required', $valueName, $groupId]
                );
            }
            if (!is_array($mapped)) {
                $mapped = $this->safeFetchOne(
                    'SELECT ay_id, ay_group_id FROM attribute_maps
                     WHERE map_type = ? AND LOWER(ps_label) = LOWER(?) AND ay_group_id = 0
                     LIMIT 1',
                    ['attribute', $valueName]
                );
            }
            if (is_array($mapped) && ((int) ($mapped['ay_id'] ?? 0)) > 0) {
                $attributes['ids'][] = (int) $mapped['ay_id'];
                if ((int) ($mapped['ay_group_id'] ?? 0) > 0) {
                    $attributes['group_ids'][] = (int) $mapped['ay_group_id'];
                }
            }
        }

        $attributes['ids'] = array_values(array_unique(array_filter($attributes['ids'])));
        $attributes['group_ids'] = array_values(array_unique(array_filter($attributes['group_ids'])));
        return $attributes;
    }

    private function resolveAttributeId(array $variant, string $type): int
    {
        return $this->resolveAttributeIdWithDiagnostics($variant, $type)['id'];
    }

    /**
     * Resolve a color / size / second_size for a single variant, returning both
     * the resolved id and whether the env-default fallback was used. Preferring
     * group-scoped matches first minimizes duplicate-tuple collisions when
     * multiple PS option groups share the same labels.
     *
     * @return array{id:int, used_default:bool, source:string}
     */
    private function resolveAttributeIdWithDiagnostics(array $variant, string $type): array
    {
        foreach (($variant['attributes'] ?? []) as $attribute) {
            $group = strtolower(trim((string) ($attribute['group_name'] ?? '')));
            if (!$this->attributeMatchesType($group, $type)) {
                continue;
            }
            $valueName = (string) ($attribute['value_name'] ?? '');
            $groupId = (int) ($attribute['group_id'] ?? 0);

            if ($groupId > 0) {
                $mapped = $this->safeFetchValue(
                    'SELECT ay_id FROM attribute_maps
                     WHERE map_type = ? AND LOWER(ps_label) = LOWER(?) AND ay_group_id = ? LIMIT 1',
                    [$type, $valueName, $groupId]
                );
                if ($mapped !== null) {
                    return ['id' => (int) $mapped, 'used_default' => false, 'source' => 'attribute_maps_group'];
                }
            }

            $mapped = $this->safeFetchValue(
                'SELECT ay_id FROM attribute_maps
                 WHERE map_type = ? AND LOWER(ps_label) = LOWER(?) AND ay_group_id = 0 LIMIT 1',
                [$type, $valueName]
            );
            if ($mapped !== null) {
                return ['id' => (int) $mapped, 'used_default' => false, 'source' => 'attribute_maps_generic'];
            }
        }

        $defaultId = (int) ($_ENV['AY_DEFAULT_' . strtoupper($type) . '_ID'] ?? 0);
        return [
            'id' => $defaultId,
            'used_default' => $defaultId > 0,
            'source' => $defaultId > 0 ? 'env_default' : 'none',
        ];
    }

    private function resolveBrandId(array $psProduct): int
    {
        if (!empty($psProduct['ay_brand_id'])) {
            return (int) $psProduct['ay_brand_id'];
        }

        $brandMap = json_decode((string) ($_ENV['AY_BRAND_MAP'] ?? '{}'), true);
        $manufacturerId = (string) ($psProduct['id_manufacturer'] ?? '');
        if (is_array($brandMap) && $manufacturerId !== '' && isset($brandMap[$manufacturerId])) {
            return $this->resolveMappedId($brandMap[$manufacturerId]);
        }

        return (int) ($_ENV['AY_BRAND_ID'] ?? 0);
    }

    private function resolveCategoryId(array $psProduct): int
    {
        if (!empty($psProduct['ay_category_id'])) {
            return (int) $psProduct['ay_category_id'];
        }

        $categoryMap = json_decode((string) ($_ENV['AY_CATEGORY_MAP'] ?? '{}'), true);
        $categoryId = (string) ($psProduct['id_category_default'] ?? $psProduct['category_ps_id'] ?? '');
        if (is_array($categoryMap) && $categoryId !== '' && isset($categoryMap[$categoryId])) {
            return $this->resolveMappedId($categoryMap[$categoryId]);
        }

        return (int) ($_ENV['AY_CATEGORY_ID'] ?? 0);
    }

    private function resolveCountryCodes(array $psProduct): array
    {
        $codes = array_filter(array_map('trim', explode(',', (string) ($_ENV['AY_COUNTRY_CODES'] ?? 'DE'))));
        return $codes !== [] ? array_values(array_unique(array_map('strtoupper', $codes))) : ['DE'];
    }

    private function buildStyleKey(array $psProduct, string $reference): string
    {
        $base = $reference !== '' ? $reference : 'ps-' . (string) ($psProduct['id'] ?? '0');
        $styleKey = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($base)) ?: ('ps-' . (string) ($psProduct['id'] ?? '0'));
        return substr($styleKey, 0, 120);
    }

    private function sanitizeDescription(string $description): string
    {
        $description = trim(strip_tags(html_entity_decode($description, ENT_QUOTES | ENT_HTML5)));
        $description = preg_replace('/\s+/', ' ', $description) ?? $description;
        return mb_substr($description, 0, 5000);
    }

    private function extractLocalizedValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        $fallback = '';
        foreach ($value as $entry) {
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $candidate = trim((string) $entry['value']);
                if ($candidate !== '') {
                    return $candidate;
                }
                if ($fallback === '') {
                    $fallback = (string) $entry['value'];
                }
            }
        }
        return $fallback;
    }

    private function isValidGtin(string $value): bool
    {
        if (!preg_match('/^(?:\d{8}|\d{12,14})$/', $value)) {
            return false;
        }

        $digits = array_map('intval', str_split($value));
        $checkDigit = array_pop($digits);
        $sum = 0;
        $multiplier = 3;
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $sum += $digits[$i] * $multiplier;
            $multiplier = $multiplier === 3 ? 1 : 3;
        }

        $computed = (10 - ($sum % 10)) % 10;
        return $computed === $checkDigit;
    }

    private function describeVariantAttribute(array $variant, string $type): string
    {
        foreach (($variant['attributes'] ?? []) as $attribute) {
            $group = trim((string) ($attribute['group_name'] ?? ''));
            if (!$this->attributeMatchesType($group, $type)) {
                continue;
            }
            $value = trim((string) ($attribute['value_name'] ?? ''));
            if ($group === '' && $value === '') {
                continue;
            }
            return trim($group . ': ' . $value, ': ');
        }
        $pairs = [];
        foreach (($variant['attributes'] ?? []) as $attribute) {
            $group = trim((string) ($attribute['group_name'] ?? ''));
            $value = trim((string) ($attribute['value_name'] ?? ''));
            if ($group === '' && $value === '') {
                continue;
            }
            $pairs[] = trim($group . ': ' . $value, ': ');
        }
        return $pairs !== [] ? 'raw=[' . implode(', ', $pairs) . ']' : '';
    }

    private function resolveMappedId(mixed $mapped): int
    {
        if (is_array($mapped)) {
            return (int) ($mapped['id'] ?? 0);
        }
        return (int) $mapped;
    }

    private function resolveRequiredAttributeGroups(array $psProduct): array
    {
        $raw = $psProduct['ay_required_attribute_groups'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $groups = [];
        foreach ($raw as $group) {
            if (!is_array($group)) {
                continue;
            }
            $id = (int) ($group['id'] ?? $group['group_id'] ?? $group['ay_group_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $required = $group['required'] ?? true;
            if (!filter_var($required, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) && $required !== true && $required !== 1 && $required !== '1') {
                continue;
            }
            $groups[] = [
                'id' => $id,
                'name' => (string) ($group['name'] ?? $group['key'] ?? ('group_' . $id)),
                'default_ay_id' => (int) ($group['default_ay_id'] ?? $group['default'] ?? 0),
            ];
        }
        return $groups;
    }

    private function resolveRequiredTextFields(array $psProduct): array
    {
        $raw = $psProduct['ay_required_text_fields'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = array_map('trim', explode(',', $raw));
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $fields = array_values(array_unique(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $raw))));
        return $fields;
    }

    private function resolveRequiredAttributeDefaults(array $psProduct): array
    {
        $requiredGroups = $this->resolveRequiredAttributeGroups($psProduct);
        if ($requiredGroups === []) {
            return ['ids' => [], 'group_ids' => [], 'warnings' => []];
        }
        $categoryId = $this->resolveCategoryId($psProduct);
        $ids = [];
        $groupIds = [];
        $warnings = [];
        foreach ($requiredGroups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $defaultId = 0;
            $source = 'none';
            $groupName = (string) ($group['name'] ?? 'group_' . $groupId);

            if ($categoryId > 0) {
                $catDefault = $this->safeFetchValue(
                    'SELECT default_ay_id FROM ay_required_group_defaults WHERE ay_category_id = ? AND ay_group_id = ? LIMIT 1',
                    [$categoryId, $groupId]
                );
                if ($catDefault !== null && (int) $catDefault > 0) {
                    $defaultId = (int) $catDefault;
                    $source = 'category_default_table';
                }
            }
            if ($defaultId <= 0) {
                $catWildcard = $this->safeFetchValue(
                    'SELECT default_ay_id FROM ay_required_group_defaults WHERE ay_category_id = 0 AND ay_group_id = ? LIMIT 1',
                    [$groupId]
                );
                if ($catWildcard !== null && (int) $catWildcard > 0) {
                    $defaultId = (int) $catWildcard;
                    $source = 'global_default_table';
                }
            }
            if ($defaultId <= 0 && (int) ($group['default_ay_id'] ?? 0) > 0) {
                $defaultId = (int) $group['default_ay_id'];
                $source = 'ay_metadata';
            }
            if ($defaultId <= 0) {
                $legacy = $this->safeFetchValue(
                    'SELECT ay_id FROM attribute_maps WHERE map_type = ? AND ay_group_id = ? AND ps_label = ? LIMIT 1',
                    ['attribute_required', $groupId, '__default__']
                );
                if ($legacy !== null && (int) $legacy > 0) {
                    $defaultId = (int) $legacy;
                    $source = 'attribute_maps_legacy_default';
                }
            }

            if ($defaultId > 0) {
                $ids[] = $defaultId;
                $groupIds[] = $groupId;
                $warnings[] = 'required group ' . $groupName . ' (id ' . $groupId . ') satisfied via ' . $source;
            } else {
                $warnings[] = 'required group ' . $groupName . ' (id ' . $groupId . ') has no default; configure ay_required_group_defaults';
            }
        }
        return [
            'ids' => array_values(array_unique(array_filter($ids))),
            'group_ids' => array_values(array_unique(array_filter($groupIds))),
            'warnings' => $warnings,
        ];
    }

    /**
     * Build a structured material composition payload for either textile or
     * non-textile. Supports three input shapes in priority order:
     *   1. already-structured input from PS/product override
     *   2. free-form text parsed via MaterialCompositionParser
     *   3. empty (no composition)
     *
     * @return array{structured: list<array<string,mixed>>, source: string, warnings: list<string>}
     */
    private function resolveStructuredMaterialComposition(array $psProduct, array $variant, bool $isTextile): array
    {
        $productId = (int) ($psProduct['id'] ?? 0);
        $key = $isTextile ? 'material_composition_textile' : 'material_composition_non_textile';

        $sources = [
            ['source' => 'variant_structured', 'value' => $variant[$key] ?? null],
            ['source' => 'product_structured', 'value' => $psProduct[$key] ?? null],
        ];
        foreach ($sources as $candidate) {
            if (is_array($candidate['value']) && $this->looksStructured($candidate['value'])) {
                return [
                    'structured' => array_values($candidate['value']),
                    'source' => $candidate['source'],
                    'warnings' => [],
                ];
            }
        }

        $rawCandidates = [];
        if ($isTextile) {
            $rawCandidates[] = ['source' => 'variant_text', 'value' => $variant['material_composition_textile'] ?? null];
            $rawCandidates[] = ['source' => 'variant_text', 'value' => $variant['material_composition'] ?? null];
            $rawCandidates[] = ['source' => 'product_text', 'value' => $psProduct['material_composition_textile'] ?? null];
            $rawCandidates[] = ['source' => 'product_text', 'value' => $psProduct['material_composition'] ?? null];
            $rawCandidates[] = ['source' => 'product_override', 'value' => $psProduct['export_material_composition'] ?? null];
            $rawCandidates[] = ['source' => 'env_default', 'value' => $_ENV['AY_DEFAULT_MATERIAL_COMPOSITION_TEXTILE'] ?? null];
            foreach (($psProduct['features'] ?? []) as $feature) {
                if (!is_array($feature)) {
                    continue;
                }
                $name = strtolower(trim((string) ($feature['name'] ?? $feature['label'] ?? $feature['key'] ?? '')));
                if ($name === '' || !str_contains($name, 'material')) {
                    continue;
                }
                $rawCandidates[] = ['source' => 'prestashop_feature', 'value' => $feature['value'] ?? null];
            }
        } else {
            $rawCandidates[] = ['source' => 'variant_text', 'value' => $variant['material_composition_non_textile'] ?? null];
            $rawCandidates[] = ['source' => 'product_text', 'value' => $psProduct['material_composition_non_textile'] ?? null];
        }

        foreach ($rawCandidates as $candidate) {
            $value = trim((string) ($candidate['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $clusters = MaterialCompositionParser::parse($value);
            if ($clusters === []) {
                continue;
            }
            $resolved = $this->materialResolver->resolve($clusters, $isTextile, $productId > 0 ? $productId : null);
            if ($resolved['structured'] !== []) {
                $warnings = [];
                if (in_array($candidate['source'], ['env_default', 'product_override'], true)) {
                    $warnings[] = $key . ' uses fallback source (' . $candidate['source'] . ')';
                }
                if (!MaterialCompositionParser::fractionsAreComplete($clusters)) {
                    $warnings[] = $key . ' parsed fractions do not sum to 100 ("' . $value . '")';
                }
                return [
                    'structured' => $resolved['structured'],
                    'source' => $candidate['source'],
                    'warnings' => array_merge($warnings, $resolved['warnings']),
                ];
            }
            if ($resolved['unresolved'] !== []) {
                return [
                    'structured' => [],
                    'source' => $candidate['source'] . '_unresolved',
                    'warnings' => array_merge(
                        [$key . ' could not be resolved; configure material_component_maps for: ' . implode(', ', $resolved['unresolved'])],
                        $resolved['warnings']
                    ),
                ];
            }
        }

        $productOverride = $this->materialResolver->resolve([], $isTextile, $productId > 0 ? $productId : null);
        if ($productOverride['structured'] !== []) {
            return [
                'structured' => $productOverride['structured'],
                'source' => 'product_override_table',
                'warnings' => [],
            ];
        }

        return [
            'structured' => [],
            'source' => 'missing',
            'warnings' => [],
        ];
    }

    private function looksStructured(array $value): bool
    {
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                return false;
            }
            if (!isset($entry['components']) || !is_array($entry['components']) || !isset($entry['cluster_id'])) {
                return false;
            }
        }
        return true;
    }

    private function safeFetchValue(string $sql, array $params): mixed
    {
        try {
            return Database::fetchValue($sql, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeFetchOne(string $sql, array $params): ?array
    {
        try {
            return Database::fetchOne($sql, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    private function attributeMatchesType(string $groupName, string $type): bool
    {
        return match ($type) {
            'color' => AttributeTypeGuesser::isColor($groupName),
            'size' => AttributeTypeGuesser::isSize($groupName),
            'second_size' => AttributeTypeGuesser::isSecondSize($groupName),
            default => false,
        };
    }
}
