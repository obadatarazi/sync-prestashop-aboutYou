<?php

namespace SyncBridge\Database;

/**
 * ProductRepository
 * All database read/write for products, variants, and images.
 */
class ProductRepository
{
    // ----------------------------------------------------------------
    // PRODUCTS
    // ----------------------------------------------------------------

    public function findAll(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'p.sync_status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(p.name LIKE ? OR p.reference LIKE ? OR p.sku LIKE ?)';
            $q = '%' . $filters['search'] . '%';
            $params[] = $q; $params[] = $q; $params[] = $q;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM products p WHERE {$whereStr}",
            $params
        );

        $rows = Database::fetchAll(
            "SELECT p.*,
                (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count,
                (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id AND i.status = 'ok') AS image_count,
                (SELECT COALESCE(NULLIF(i.public_url, ''), NULLIF(i.source_url, ''))
                 FROM product_images i
                 WHERE i.product_id = p.id
                 ORDER BY (i.status = 'ok') DESC, i.position ASC, i.id ASC
                 LIMIT 1) AS image_thumb_url,
                (SELECT e.reason_code
                 FROM product_sync_errors e
                 WHERE e.product_id = p.id
                 ORDER BY e.id DESC
                 LIMIT 1) AS last_error_reason_code,
                (SELECT e.error_message
                 FROM product_sync_errors e
                 WHERE e.product_id = p.id
                 ORDER BY e.id DESC
                 LIMIT 1) AS last_error_message,
                CASE
                    WHEN p.sync_status = 'synced' THEN 'Synced on AboutYou'
                    WHEN p.sync_status = 'syncing' THEN 'Currently syncing'
                    WHEN p.sync_status = 'pending' THEN 'Pending sync'
                    WHEN p.sync_status = 'quarantined' THEN 'Quarantined'
                    WHEN COALESCE(NULLIF(p.ay_style_key, ''), '') = '' THEN 'Missing AY style key'
                    WHEN COALESCE(NULLIF((SELECT e.error_message FROM product_sync_errors e WHERE e.product_id = p.id ORDER BY e.id DESC LIMIT 1), ''), '') <> '' THEN (SELECT e.error_message FROM product_sync_errors e WHERE e.product_id = p.id ORDER BY e.id DESC LIMIT 1)
                    WHEN p.sync_status = 'error' AND COALESCE(NULLIF(p.sync_error, ''), '') <> '' THEN p.sync_error
                    WHEN p.sync_status = 'error' THEN 'Sync failed; open product details for required fields'
                    ELSE 'Needs sync review'
                END AS readiness_reason
             FROM products p
             WHERE {$whereStr}
             ORDER BY p.ps_id ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['total' => $total, 'rows' => $rows, 'page' => $page, 'per_page' => $perPage];
    }

    public function findComparison(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;
        $where = ['1=1'];
        $params = [];
        $bucket = strtolower(trim((string) ($filters['bucket'] ?? '')));
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $where[] = '(p.name LIKE ? OR p.reference LIKE ? OR p.sku LIKE ? OR p.ay_style_key LIKE ?)';
            $q = '%' . $search . '%';
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        $syncedExpr = "(p.sync_status = 'synced')";
        if ($bucket === 'synced') {
            $where[] = $syncedExpr;
        } elseif ($bucket === 'not_synced') {
            $where[] = "NOT ({$syncedExpr})";
        }

        $whereStr = implode(' AND ', $where);

        $total = (int) Database::fetchValue(
            "SELECT COUNT(*) FROM products p WHERE {$whereStr}",
            $params
        );

        $rows = Database::fetchAll(
            "SELECT p.*,
                (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count,
                (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id AND i.status = 'ok') AS image_count,
                (SELECT COALESCE(NULLIF(i.public_url, ''), NULLIF(i.source_url, ''))
                 FROM product_images i
                 WHERE i.product_id = p.id
                 ORDER BY (i.status = 'ok') DESC, i.position ASC, i.id ASC
                 LIMIT 1) AS image_thumb_url,
                (SELECT e.reason_code
                 FROM product_sync_errors e
                 WHERE e.product_id = p.id
                 ORDER BY e.id DESC
                 LIMIT 1) AS last_error_reason_code,
                (SELECT e.error_message
                 FROM product_sync_errors e
                 WHERE e.product_id = p.id
                 ORDER BY e.id DESC
                 LIMIT 1) AS last_error_message,
                CASE
                    WHEN {$syncedExpr} THEN 'synced'
                    ELSE 'not_synced'
                END AS comparison_bucket,
                CASE
                    WHEN p.sync_status = 'synced' THEN 'Synced on AboutYou'
                    WHEN p.sync_status = 'syncing' THEN 'Currently syncing'
                    WHEN p.sync_status = 'pending' THEN 'Pending sync'
                    WHEN p.sync_status = 'quarantined' THEN 'Quarantined'
                    WHEN COALESCE(NULLIF(p.ay_style_key, ''), '') = '' THEN 'Missing AY style key'
                    WHEN COALESCE(NULLIF((SELECT e.error_message FROM product_sync_errors e WHERE e.product_id = p.id ORDER BY e.id DESC LIMIT 1), ''), '') <> '' THEN (SELECT e.error_message FROM product_sync_errors e WHERE e.product_id = p.id ORDER BY e.id DESC LIMIT 1)
                    WHEN p.sync_status = 'error' AND COALESCE(NULLIF(p.sync_error, ''), '') <> '' THEN p.sync_error
                    WHEN p.sync_status = 'error' THEN 'Sync failed; open product details for required fields'
                    ELSE 'Not synced'
                END AS comparison_reason
             FROM products p
             WHERE {$whereStr}
             ORDER BY p.ps_id ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $summary = Database::fetchOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN {$syncedExpr} THEN 1 ELSE 0 END) AS synced,
                SUM(CASE WHEN NOT ({$syncedExpr}) THEN 1 ELSE 0 END) AS not_synced,
                SUM(CASE WHEN p.sync_status = 'error' THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN p.sync_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN COALESCE(NULLIF(p.ay_style_key, ''), '') = '' THEN 1 ELSE 0 END) AS missing_style_key
             FROM products p
             WHERE {$whereStr}",
            $params
        ) ?? [];

        return [
            'total' => $total,
            'rows' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'bucket' => $bucket,
            'summary' => $summary,
        ];
    }

    public function findByPsId(int $psId): ?array
    {
        return Database::fetchOne('SELECT * FROM products WHERE ps_id = ?', [$psId]);
    }

    public function findById(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM products WHERE id = ?', [$id]);
    }

    public function findByPsIds(array $psIds): array
    {
        $psIds = array_values(array_unique(array_filter(array_map('intval', $psIds), static fn (int $id): bool => $id > 0)));
        if ($psIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($psIds), '?'));
        return Database::fetchAll(
            "SELECT * FROM products WHERE ps_id IN ({$placeholders}) ORDER BY ps_id ASC",
            $psIds
        );
    }

    public function upsertFromPrestaShop(array $psProduct): int
    {
        $name = '';
        $nameArr = $psProduct['name'] ?? [];
        if (is_array($nameArr)) {
            foreach ($nameArr as $entry) {
                if ((int)($entry['id'] ?? 0) === (int)($_ENV['PS_LANGUAGE_ID'] ?? 1)) {
                    $name = $entry['value'] ?? '';
                    break;
                }
            }
            if ($name === '' && !empty($nameArr[0]['value'])) {
                $name = $nameArr[0]['value'];
            }
        } elseif (is_string($nameArr)) {
            $name = $nameArr;
        }

        $desc = $this->extractLangValue($psProduct['description'] ?? []);
        $descShort = $this->extractLangValue($psProduct['description_short'] ?? []);
        $materialComposition = $this->extractMaterialComposition($psProduct);

        $data = [
            'ps_id'           => (int) $psProduct['id'],
            'reference'       => trim((string) ($psProduct['reference'] ?? '')),
            'name'            => $name,
            'description'     => $desc,
            'description_short' => $descShort,
            'export_material_composition' => $materialComposition,
            'price'           => (float) ($psProduct['price'] ?? 0),
            'weight'          => (float) ($psProduct['weight'] ?? 0),
            'ean13'           => trim((string) ($psProduct['ean13'] ?? '')) ?: null,
            'category_ps_id'  => (int) ($psProduct['id_category_default'] ?? 0) ?: null,
            'active'          => (int) ($psProduct['active'] ?? 1),
            'ps_updated_at'   => $psProduct['date_upd'] ?? null,
        ];

        Database::execute(
            "INSERT INTO products (ps_id, reference, name, description, description_short,
                export_material_composition, price, weight, ean13, category_ps_id, active, ps_updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               reference = VALUES(reference), name = VALUES(name),
               description = VALUES(description), description_short = VALUES(description_short),
               export_material_composition = COALESCE(NULLIF(VALUES(export_material_composition), ''), export_material_composition),
               price = VALUES(price), weight = VALUES(weight), ean13 = VALUES(ean13),
               category_ps_id = VALUES(category_ps_id), active = VALUES(active),
               -- Keep operator-assigned AboutYou category/brand untouched on re-fetch.
               ps_updated_at = VALUES(ps_updated_at), updated_at = NOW()",
            array_values($data)
        );

        $row = Database::fetchOne('SELECT id FROM products WHERE ps_id = ?', [$data['ps_id']]);
        return (int) ($row['id'] ?? 0);
    }

    public function markSynced(int $id, string $ayStyleKey): void
    {
        Database::execute(
            "UPDATE products SET sync_status='synced', ay_style_key=?, sync_error=NULL, ay_missing_payload_json=NULL,
             last_synced_at=NOW() WHERE id=?",
            [$ayStyleKey, $id]
        );
    }

    public function markError(int $id, string $error): void
    {
        Database::execute(
            "UPDATE products SET sync_status='error', sync_error=?, updated_at=NOW() WHERE id=?",
            [$error, $id]
        );
    }

    public function markSyncing(int $id): void
    {
        Database::execute(
            "UPDATE products SET sync_status='syncing', updated_at=NOW() WHERE id=?",
            [$id]
        );
    }

    public function saveExportOverridesByPsId(int $psId, array $data): void
    {
        Database::execute(
            "UPDATE products
             SET export_title = ?, export_description = ?, export_material_composition = ?, ps_api_payload = ?,
                 ay_manual_required_attributes_json = ?, ay_category_id = ?, ay_brand_id = ?, updated_at = NOW()
             WHERE ps_id = ?",
            [
                $this->nullableString($data['export_title'] ?? null),
                $this->nullableString($data['export_description'] ?? null),
                $this->nullableString($data['export_material_composition'] ?? null),
                $this->nullableString($data['ps_api_payload'] ?? null),
                $this->nullableString($data['ay_manual_required_attributes_json'] ?? null),
                $this->nullableInt($data['ay_category_id'] ?? null),
                $this->nullableInt($data['ay_brand_id'] ?? null),
                $psId,
            ]
        );
    }

    public function getStats(): array
    {
        return Database::fetchOne(
            "SELECT
               COUNT(*) AS total,
               SUM(sync_status='synced') AS synced,
               SUM(sync_status='pending') AS pending,
               SUM(sync_status='error') AS error,
               SUM(sync_status='quarantined') AS quarantined
             FROM products"
        ) ?? [];
    }

    // ----------------------------------------------------------------
    // VARIANTS
    // ----------------------------------------------------------------

    public function getVariants(int $productId): array
    {
        return Database::fetchAll(
            'SELECT * FROM product_variants WHERE product_id = ? ORDER BY ps_combo_id',
            [$productId]
        );
    }

    public function upsertVariant(int $productId, array $combo, float $basePrice): void
    {
        $comboId = (int) ($combo['id'] ?? 0);
        $ref     = trim((string) ($combo['reference'] ?? ''));
        $sku     = $ref !== '' ? $ref : "PS-{$productId}" . ($comboId > 0 ? "-{$comboId}" : '');

        Database::execute(
            "INSERT INTO product_variants
               (product_id, ps_combo_id, sku, ean13, reference, price_modifier, quantity)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
              sku=VALUES(sku),
              -- Keep local/manual EAN edits stable across PS re-fetches.
              ean13=CASE
                    WHEN COALESCE(NULLIF(product_variants.ean13, ''), '') <> '' THEN product_variants.ean13
                    ELSE VALUES(ean13)
                  END,
              reference=VALUES(reference),
               price_modifier=VALUES(price_modifier), quantity=VALUES(quantity),
               updated_at=NOW()",
            [
                $productId, $comboId, $sku,
                trim((string)($combo['ean13'] ?? '')) ?: null,
                $ref ?: null,
                (float) ($combo['price'] ?? 0),
                (int) ($combo['quantity'] ?? 0),
            ]
        );
    }

    public function saveVariantEansByPsId(int $psId, array $variantEans): int
    {
        $product = $this->findByPsId($psId);
        if (!$product) {
            return 0;
        }
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            return 0;
        }

        $saved = 0;
        foreach ($variantEans as $row) {
            if (!is_array($row)) {
                continue;
            }
            $comboId = (int) ($row['ps_combo_id'] ?? 0);
            if ($comboId <= 0) {
                continue;
            }
            $ean = $this->nullableString($row['ean13'] ?? null);
            Database::execute(
                "UPDATE product_variants
                 SET ean13 = ?, updated_at = NOW()
                 WHERE product_id = ? AND ps_combo_id = ?",
                [$ean, $productId, $comboId]
            );
            $saved++;
        }
        return $saved;
    }

    // ----------------------------------------------------------------
    // IMAGES
    // ----------------------------------------------------------------

    public function getImages(int $productId): array
    {
        return Database::fetchAll(
            'SELECT * FROM product_images WHERE product_id = ? ORDER BY position',
            [$productId]
        );
    }

    public function findImageByProductAndPsImageId(int $productId, string $psImageId): ?array
    {
        return Database::fetchOne(
            "SELECT *
             FROM product_images
             WHERE product_id = ? AND ps_image_id = ?
             ORDER BY
               (CASE WHEN width >= 1125 AND height >= 1500 THEN 1 ELSE 0 END) DESC,
               (CASE WHEN public_url LIKE '%/ay-normalized/%' THEN 1 ELSE 0 END) DESC,
               id DESC
             LIMIT 1",
            [$productId, $psImageId]
        );
    }

    public function upsertImage(int $productId, string $sourceUrl, string $psImageId, int $position): int
    {
        $existing = null;
        if ($psImageId !== '') {
            $existing = Database::fetchOne(
                "SELECT id
                 FROM product_images
                 WHERE product_id = ? AND ps_image_id = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$productId, $psImageId]
            );
        } else {
            $existing = Database::fetchOne(
                "SELECT id
                 FROM product_images
                 WHERE product_id = ? AND source_url = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$productId, $sourceUrl]
            );
        }

        if ($existing !== null) {
            $imageId = (int) ($existing['id'] ?? 0);
            if ($imageId > 0) {
                Database::execute(
                    'UPDATE product_images SET source_url = ?, position = ?, updated_at = NOW() WHERE id = ?',
                    [$sourceUrl, $position, $imageId]
                );
                return $imageId;
            }
        }

        Database::execute(
            "INSERT INTO product_images (product_id, ps_image_id, source_url, position, status)
             VALUES (?, ?, ?, ?, 'pending')",
            [$productId, $psImageId, $sourceUrl, $position]
        );
        return Database::lastInsertId();
    }

    public function findExternalEanConflicts(int $productId, array $eans): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $eans
        ))));
        if ($productId <= 0 || $normalized === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $params = [$productId, ...$normalized];
        return Database::fetchAll(
            "SELECT v.ean13, p.ps_id, p.name, p.ay_style_key, v.ps_combo_id
             FROM product_variants v
             JOIN products p ON p.id = v.product_id
             WHERE v.product_id <> ?
               AND v.ean13 IN ({$placeholders})
             ORDER BY v.ean13 ASC, p.ps_id ASC, v.ps_combo_id ASC",
            $params
        );
    }

    public function markImageOk(int $imageId, string $localPath, string $publicUrl, int $w, int $h, int $bytes): void
    {
        Database::execute(
            "UPDATE product_images SET status='ok', local_path=?, public_url=?,
             width=?, height=?, file_size_bytes=?, processed_at=NOW(), error_message=NULL
             WHERE id=?",
            [$localPath, $publicUrl, $w, $h, $bytes, $imageId]
        );
    }

    public function markImageError(int $imageId, string $error): void
    {
        Database::execute(
            "UPDATE product_images SET status='error', error_message=?, processed_at=NOW() WHERE id=?",
            [$error, $imageId]
        );
    }

    public function markImageOkFromSource(int $imageId): void
    {
        Database::execute(
            "UPDATE product_images
             SET status='ok',
                 local_path=NULL,
                 public_url=source_url,
                 width=NULL,
                 height=NULL,
                 file_size_bytes=NULL,
                 processed_at=NOW(),
                 error_message=NULL
             WHERE id=?",
            [$imageId]
        );
    }

    public function getImageStats(): array
    {
        return Database::fetchOne(
            "SELECT COUNT(*) AS total,
               SUM(status='ok') AS ok,
               SUM(status='error') AS error,
               SUM(status='pending') AS pending,
               SUM(status='processing') AS processing
             FROM product_images"
        ) ?? [];
    }

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------

    private function extractLangValue(mixed $val): string
    {
        if (is_string($val)) return $val;
        if (!is_array($val)) return '';
        $langId = (int) ($_ENV['PS_LANGUAGE_ID'] ?? 1);
        if (isset($val[0]['value'])) {
            foreach ($val as $e) {
                if ((int)($e['id'] ?? 0) === $langId && trim((string)($e['value'] ?? '')) !== '') {
                    return (string)($e['value'] ?? '');
                }
            }
            foreach ($val as $e) {
                if (trim((string)($e['value'] ?? '')) !== '') {
                    return (string)($e['value'] ?? '');
                }
            }
            return (string)($val[0]['value'] ?? '');
        }
        return '';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function extractMaterialComposition(array $psProduct): ?string
    {
        $candidates = [
            $psProduct['material_composition_textile'] ?? null,
            $psProduct['material_composition'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }
        foreach (($psProduct['features'] ?? []) as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $name = strtolower(trim((string) ($feature['name'] ?? $feature['label'] ?? $feature['key'] ?? '')));
            if ($name === '' || !str_contains($name, 'material')) {
                continue;
            }
            $value = trim((string) ($feature['value'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }
}
