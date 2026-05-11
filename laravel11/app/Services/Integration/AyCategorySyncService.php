<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\AyCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class AyCategorySyncService
{
    private const PER_PAGE = 100;

    private const MAX_PAGES_PER_PARENT = 200;

    private const MAX_QUEUE_POPS = 50_000;

    public function __construct(private readonly AboutYouClient $aboutYouClient) {}

    /**
     * Walk the About You category tree (BFS), upsert rows, then remove categories missing from this run.
     *
     * @return array{discovered:int, pruned:int}
     */
    public function syncFullTree(): array
    {
        $mark = CarbonImmutable::now();
        $discovered = 0;
        $pops = 0;

        $queue = [null];
        $enqueuedParent = [true];

        while ($queue !== []) {
            if (++$pops > self::MAX_QUEUE_POPS) {
                Log::warning('ay_categories.sync_aborted_queue_limit', ['mark' => $mark->toIso8601String()]);
                break;
            }
            $parentId = array_shift($queue);

            $children = $this->fetchAllChildrenPages($parentId);
            foreach ($children as $row) {
                $this->upsertRow($row, $mark);
                $discovered++;
                $cid = $row['id'];
                if (!isset($enqueuedParent[$cid])) {
                    $queue[] = $cid;
                    $enqueuedParent[$cid] = true;
                }
            }
        }

        $pruned = (int) AyCategory::query()->where(function ($q) use ($mark): void {
            $q->where('last_seen_at', '<', $mark)->orWhereNull('last_seen_at');
        })->delete();

        Log::info('ay_categories.sync_complete', [
            'discovered' => $discovered,
            'pruned' => $pruned,
            'mark' => $mark->toIso8601String(),
        ]);

        return ['discovered' => $discovered, 'pruned' => $pruned];
    }

    /**
     * @return list<array{id:int,name:string,path:string,parent_id:int|null}>
     */
    private function fetchAllChildrenPages(?int $parentId): array
    {
        $merged = [];
        $page = 1;
        while ($page <= self::MAX_PAGES_PER_PARENT) {
            $result = $this->aboutYouClient->searchCategories(null, $page, self::PER_PAGE, $parentId);
            $items = $result['items'] ?? [];
            if (!is_array($items) || $items === []) {
                break;
            }
            $normalized = $this->normalizeItems($items);
            if ($normalized === []) {
                break;
            }
            foreach ($normalized as $row) {
                $merged[] = $row;
            }

            $pagination = $result['pagination'] ?? null;
            $moreByPagination = false;
            if (is_array($pagination)) {
                $cur = (int) ($pagination['page'] ?? $page);
                $pages = (int) ($pagination['pages'] ?? 0);
                if ($pages > 0 && $cur < $pages) {
                    $moreByPagination = true;
                }
            }

            if ($moreByPagination) {
                $page++;
                continue;
            }
            if (count($normalized) < self::PER_PAGE) {
                break;
            }
            $page++;
        }

        return $merged;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array{id:int,name:string,path:string,parent_id:int|null}>
     */
    private function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
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
            $out[] = [
                'id' => $id,
                'name' => (string) ($item['name'] ?? ''),
                'path' => (string) ($item['path'] ?? $item['name'] ?? ''),
                'parent_id' => $pid,
            ];
        }

        return $out;
    }

    /**
     * @param array{id:int,name:string,path:string,parent_id:int|null} $row
     */
    private function upsertRow(array $row, CarbonImmutable $mark): void
    {
        $now = $mark->toDateTimeString();
        AyCategory::query()->upsert(
            [[
                'id' => $row['id'],
                'parent_id' => $row['parent_id'],
                'name' => $row['name'],
                'path' => $row['path'],
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['id'],
            ['parent_id', 'name', 'path', 'last_seen_at', 'updated_at']
        );
    }
}
