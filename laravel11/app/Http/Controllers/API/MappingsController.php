<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttributeMap;
use App\Models\AyCategory;
use App\Models\AyRequiredGroupDefault;
use App\Models\MaterialClusterMap;
use App\Models\MaterialComponentMap;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Integration\AboutYouClient;
use App\Services\Integration\PrestaShopClient;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class MappingsController extends Controller
{
    use ApiResponseTrait;

    public function categories(PrestaShopClient $prestaShopClient)
    {
        $categories = $prestaShopClient->getAllCategories();
        $counts = Product::query()
            ->selectRaw('category_ps_id, COUNT(*) as cnt')
            ->whereNotNull('category_ps_id')
            ->groupBy('category_ps_id')
            ->pluck('cnt', 'category_ps_id');

        $rawMap = (string) (Setting::query()->find('ay_category_map')?->value ?? '{}');
        $map = json_decode($rawMap, true);
        if (!is_array($map)) {
            $map = [];
        }

        $rows = array_map(static function (array $category) use ($counts, $map): array {
            $psCategoryId = (int) ($category['id'] ?? 0);
            $mapEntry = $map[(string) $psCategoryId] ?? [];
            $mapEntry = is_array($mapEntry) ? $mapEntry : [];

            return [
                'ps_category_id' => $psCategoryId,
                'ps_category_name' => (string) ($category['name'] ?? ''),
                'product_count' => (int) ($counts[$psCategoryId] ?? 0),
                'ay_category_id' => (int) ($mapEntry['id'] ?? 0) ?: null,
                'ay_category_path' => (string) ($mapEntry['path'] ?? '') ?: null,
            ];
        }, $categories);

        return $this->success(['rows' => $rows]);
    }

    public function saveCategories(Request $request)
    {
        $mappings = $request->input('mappings', []);
        if (!is_array($mappings)) {
            return $this->error('Invalid mappings payload');
        }

        $normalized = [];
        foreach ($mappings as $psCategoryId => $mapping) {
            $psId = (int) $psCategoryId;
            if ($psId <= 0 || !is_array($mapping)) {
                continue;
            }
            $ayId = (int) ($mapping['id'] ?? 0);
            if ($ayId <= 0) {
                continue;
            }
            $normalized[(string) $psId] = [
                'id' => $ayId,
                'path' => trim((string) ($mapping['path'] ?? '')),
            ];
        }

        Setting::query()->updateOrCreate(
            ['key' => 'ay_category_map'],
            [
                'value' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type' => 'json',
                'label' => 'Category Map JSON',
                'group_name' => 'aboutyou',
            ]
        );

        return $this->success(['saved' => count($normalized)]);
    }

    public function searchAyCategories(Request $request, AboutYouClient $aboutYouClient)
    {
        $query = trim((string) $request->query('q', ''));
        $rawParent = $request->query('parent_category');
        $parentId = null;
        if ($rawParent !== null && $rawParent !== '') {
            $parsed = (int) $rawParent;
            if ($parsed > 0) {
                $parentId = $parsed;
            }
        }
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 100)));

        if (AyCategory::query()->exists()) {
            $normalized = $this->searchAyCategoriesFromDatabase($query, $parentId, $page, $perPage);
            if ($normalized === [] && $parentId === null && $query === '' && $page === 1) {
                $normalized = $this->searchAyCategoriesFromApi($aboutYouClient, $query, $parentId, $page, $perPage);
            }
        } else {
            $normalized = $this->searchAyCategoriesFromApi($aboutYouClient, $query, $parentId, $page, $perPage);
        }

        return $this->success(['items' => $normalized]);
    }

    /**
     * @return list<array{id:int,name:string,path:string,parent_id:int|null}>
     */
    private function searchAyCategoriesFromDatabase(string $query, ?int $parentId, int $page, int $perPage): array
    {
        $q = AyCategory::query();

        if ($query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $q->where(static function ($w) use ($like): void {
                $w->where('name', 'like', $like)->orWhere('path', 'like', $like);
            });
            if ($parentId !== null) {
                $q->where('parent_id', $parentId);
            }
        } elseif ($parentId !== null) {
            $q->where('parent_id', $parentId);
        } else {
            // Tree roots: no parent, or parent id not present in table (sync/API sometimes omit NULL parent_id).
            $q->where(function ($w): void {
                $w->whereNull('parent_id')
                    ->orWhereNotIn('parent_id', AyCategory::query()->select('id'));
            });
        }

        $rows = $q->orderBy('path')->forPage($page, $perPage)->get();

        return $rows->map(static function (AyCategory $c): array {
            return [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'path' => (string) $c->path,
                'parent_id' => $c->parent_id !== null ? (int) $c->parent_id : null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{id:int,name:string,path:string,parent_id:int|null}>
     */
    private function searchAyCategoriesFromApi(
        AboutYouClient $aboutYouClient,
        string $query,
        ?int $parentId,
        int $page,
        int $perPage
    ): array {
        $result = $aboutYouClient->searchCategories(
            $query !== '' ? $query : null,
            $page,
            $perPage,
            $parentId
        );
        $items = $result['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $normalized = array_values(array_filter(array_map(static function (mixed $item): ?array {
            if (!is_array($item)) {
                return null;
            }

            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                return null;
            }

            $rawParentId = $item['parent_id'] ?? null;
            if ($rawParentId === null && array_key_exists('parent', $item)) {
                $parent = $item['parent'];
                if (is_array($parent)) {
                    $rawParentId = $parent['id'] ?? $parent['parent_id'] ?? null;
                } else {
                    $rawParentId = $parent;
                }
            }

            $pid = null;
            if (is_int($rawParentId) || (is_string($rawParentId) && ctype_digit($rawParentId))) {
                $p = (int) $rawParentId;
                $pid = $p > 0 ? $p : null;
            }

            return [
                'id' => $id,
                'name' => (string) ($item['name'] ?? ''),
                'path' => (string) ($item['path'] ?? $item['name'] ?? ''),
                'parent_id' => $pid,
            ];
        }, $items)));

        if ($parentId === null && $query === '') {
            $rootsOnly = array_values(array_filter(
                $normalized,
                static fn (array $row): bool => ($row['parent_id'] ?? null) === null
            ));
            if ($rootsOnly !== []) {
                $normalized = $rootsOnly;
            }
        }

        return $normalized;
    }

    public function overview()
    {
        $attributeCount = AttributeMap::query()->count();
        $materialComponentCount = MaterialComponentMap::query()->count();
        $materialClusterCount = MaterialClusterMap::query()->count();
        $requiredDefaultsCount = AyRequiredGroupDefault::query()->count();

        return $this->success([
            'attribute_maps_count' => $attributeCount,
            'material_component_maps_count' => $materialComponentCount,
            'material_cluster_maps_count' => $materialClusterCount,
            'required_group_defaults_count' => $requiredDefaultsCount,
        ]);
    }
}

