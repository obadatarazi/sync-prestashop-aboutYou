import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fetchAyCategories } from '@/api/mappings'
import { normalizeAxiosError } from '@/api/errors'
import { toastError } from '@/store/toastStore'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'
import type { AyCategorySearchItem } from '@/types/api'

const PATH_SEP = '|'

function pathSegments(path: string): string[] {
  return path
    .split(PATH_SEP)
    .map((s) => s.trim())
    .filter(Boolean)
}

function joinPathSegments(parts: string[]): string {
  return parts.join(PATH_SEP)
}

function pathDepth(path: string): number {
  return pathSegments(path).length
}

function normHead(head: string): string {
  return head
    .trim()
    .toUpperCase()
    .replace(/[''`´]/g, '')
    .replace(/\s+/g, '')
}

/** First segment of path (e.g. Boys from Boys|Accessories|…). */
function firstSegment(item: AyCategorySearchItem): string {
  const s = pathSegments(item.path || item.name)
  return (s[0]?.trim() || item.name.trim() || '').trim()
}

/** Known top-level departments (About You style). Used for ordering and filtering junk rows like "Coat". */
const HEAD_ORDER: Record<string, number> = {
  MAN: 0,
  MEN: 0,
  MENS: 0,
  WOMAN: 1,
  WOMEN: 1,
  WOMENS: 1,
  LADIES: 1,
  LADY: 1,
  KIDS: 2,
  KID: 2,
  CHILD: 2,
  CHILDREN: 2,
  BOYS: 3,
  BOY: 3,
  GIRLS: 4,
  GIRL: 4,
  BABY: 5,
  BABIES: 5,
  UNISEX: 6,
  HOME: 7,
  BEAUTY: 8,
}

const HEAD_LABEL: Record<string, string> = {
  MAN: 'Man',
  MEN: 'Man',
  MENS: 'Man',
  WOMAN: 'Woman',
  WOMEN: 'Woman',
  WOMENS: 'Woman',
  LADIES: 'Woman',
  LADY: 'Woman',
  KIDS: 'Kids',
  KID: 'Kids',
  CHILD: 'Kids',
  CHILDREN: 'Kids',
  BOYS: 'Boys',
  BOY: 'Boys',
  GIRLS: 'Girls',
  GIRL: 'Girls',
  BABY: 'Baby',
  BABIES: 'Baby',
  UNISEX: 'Unisex',
  HOME: 'Home',
  BEAUTY: 'Beauty',
}

function tier1HeadRank(head: string): number {
  const u = normHead(head)
  if (HEAD_ORDER[u] !== undefined) {
    return HEAD_ORDER[u]!
  }
  return 50
}

function isKnownDepartmentHead(head: string): boolean {
  return HEAD_ORDER[normHead(head)] !== undefined
}

function humanizeDepartmentButtonLabel(item: AyCategorySearchItem): string {
  const u = normHead(firstSegment(item))
  if (HEAD_LABEL[u]) {
    return HEAD_LABEL[u]!
  }
  const raw = firstSegment(item)
  if (!raw) {
    return item.name || '—'
  }
  return raw.charAt(0).toUpperCase() + raw.slice(1).toLowerCase()
}

function compareDepartmentOptions(a: AyCategorySearchItem, b: AyCategorySearchItem): number {
  const ra = tier1HeadRank(firstSegment(a))
  const rb = tier1HeadRank(firstSegment(b))
  if (ra !== rb) {
    return ra - rb
  }
  return (a.path || a.name).localeCompare(b.path || b.name, undefined, { sensitivity: 'base' })
}

/**
 * When the API returns a flat list of deep paths (no parent_id), collapse to one row per top-level
 * segment (Boys, Girls, …), preferring the shallowest path so the row id is as close to the branch root as possible.
 */
function collapseByFirstSegmentShallowest(roots: AyCategorySearchItem[]): AyCategorySearchItem[] {
  const map = new Map<string, AyCategorySearchItem>()
  for (const r of roots) {
    const head = firstSegment(r)
    if (!head) {
      continue
    }
    const key = normHead(head)
    const cur = map.get(key)
    if (!cur) {
      map.set(key, r)
      continue
    }
    const d = pathDepth(r.path || r.name)
    const cd = pathDepth(cur.path || cur.name)
    if (d === 1) {
      map.set(key, r)
      continue
    }
    if (cd === 1) {
      continue
    }
    if (d < cd) {
      map.set(key, r)
      continue
    }
    if (d === cd && (r.path || '').length < (cur.path || '').length) {
      map.set(key, r)
    }
  }
  return [...map.values()].sort(compareDepartmentOptions)
}

/**
 * Step 1: only true tree roots (parent_id null), or one shallowest candidate per first path segment.
 * Prefer rows whose first segment is a known department (drops stray leaves like "Coat" when other departments exist).
 */
function pickDepartmentStepOptions(roots: AyCategorySearchItem[]): AyCategorySearchItem[] {
  if (roots.length === 0) {
    return []
  }
  const explicitRoots = roots.filter((r) => r.parent_id === null || r.parent_id === undefined)
  let out: AyCategorySearchItem[]
  if (explicitRoots.length > 0) {
    out = [...explicitRoots].sort(compareDepartmentOptions)
  } else {
    out = collapseByFirstSegmentShallowest(roots)
  }
  const known = out.filter((r) => isKnownDepartmentHead(firstSegment(r)))
  return known.length > 0 ? known : out
}

/** Keep only rows under the chosen branch (path prefix), in case the API returns extra rows. */
function filterUnderParentPath(parent: AyCategorySearchItem, items: AyCategorySearchItem[]): AyCategorySearchItem[] {
  const base = (parent.path || parent.name).trim()
  if (!base) {
    return items
  }
  const u = base.toUpperCase()
  const pref = u + PATH_SEP
  return items.filter((row) => {
    const p = (row.path || row.name).trim().toUpperCase()
    return p === u || p.startsWith(pref)
  })
}

/** Longest path prefix (by segments) shared by every non-empty path in the list. */
function longestCommonPathPrefix(paths: string[]): string {
  const allSeg = paths.map(pathSegments).filter((p) => p.length > 0)
  if (allSeg.length === 0) {
    return ''
  }
  const minLen = Math.min(...allSeg.map((p) => p.length))
  const shared: string[] = []
  for (let i = 0; i < minLen; i++) {
    const seg = allSeg[0]![i]
    if (!allSeg.every((p) => p[i] === seg)) {
      break
    }
    shared.push(seg)
  }
  return joinPathSegments(shared)
}

function stripSharedPathPrefix(path: string, prefix: string): string {
  if (!prefix.trim()) {
    return path
  }
  const p = pathSegments(path)
  const pre = pathSegments(prefix)
  if (pre.length === 0 || p.length < pre.length) {
    return path
  }
  for (let i = 0; i < pre.length; i++) {
    if (p[i] !== pre[i]) {
      return path
    }
  }
  const tail = joinPathSegments(p.slice(pre.length))
  return tail || path
}

function formatAyCategoryOptionLabel(pathRaw: string, siblingPaths: string[]): string {
  const path = pathRaw.trim() || pathRaw
  const paths = siblingPaths.map((s) => s.trim()).filter(Boolean)
  if (!path) {
    return pathRaw
  }
  if (paths.length <= 1) {
    const s = pathSegments(path)
    return s.length ? s[s.length - 1]! : path
  }
  const lcp = longestCommonPathPrefix(paths)
  const rest = stripSharedPathPrefix(path, lcp)
  if (rest && rest !== path) {
    return rest
  }
  const s = pathSegments(path)
  return s.length ? s[s.length - 1]! : path
}

type Props = {
  /** Stable key so each table row resets its own cascade */
  rowKey: number
  roots: AyCategorySearchItem[]
  /** True while the roots query is loading or refetching (avoids empty flash in modals). */
  rootsLoading: boolean
  rootsError: boolean
  onSelect: (mapping: { id: number; path: string }) => void
}

export function AyCategoryCascadePicker({ rowKey: _rowKey, roots, rootsLoading, rootsError, onSelect }: Props) {
  const { t } = useTranslation('common')
  const { t: tm } = useTranslation('mappingsPage')
  const [department, setDepartment] = useState<AyCategorySearchItem | null>(null)
  const [subLevels, setSubLevels] = useState<AyCategorySearchItem[][]>([])
  const [subChosen, setSubChosen] = useState<AyCategorySearchItem[]>([])
  const [loading, setLoading] = useState(false)

  const departmentOptions = useMemo(() => pickDepartmentStepOptions(roots), [roots])

  const resetAll = () => {
    setDepartment(null)
    setSubLevels([])
    setSubChosen([])
  }

  const applyItem = (item: AyCategorySearchItem) => {
    onSelect({ id: item.id, path: item.path })
  }

  const refineChildren = (parent: AyCategorySearchItem, items: AyCategorySearchItem[]) => {
    const filtered = filterUnderParentPath(parent, items)
    return filtered.length > 0 ? filtered : items
  }

  const handleDepartmentPick = async (item: AyCategorySearchItem) => {
    setDepartment(item)
    setSubChosen([])
    setSubLevels([])
    setLoading(true)
    try {
      const res = await fetchAyCategories({ parent_category: item.id, per_page: 100 })
      const refined = refineChildren(item, res.items)
      if (refined.length > 0) {
        setSubLevels([refined])
      } else {
        applyItem(item)
      }
    } catch (e) {
      toastError(tm('ayCategoriesLoadError'), normalizeAxiosError(e))
      setDepartment(null)
    } finally {
      setLoading(false)
    }
  }

  const handleSubLevelChange = async (levelIndex: number, rawValue: string) => {
    const id = Number(rawValue)
    if (!id) {
      return
    }
    const options = subLevels[levelIndex]
    const item = options.find((i) => i.id === id)
    if (!item) {
      return
    }

    const nextChosen = [...subChosen.slice(0, levelIndex), item]
    setSubChosen(nextChosen)
    setSubLevels((prev) => prev.slice(0, levelIndex + 1))

    setLoading(true)
    try {
      const res = await fetchAyCategories({ parent_category: id, per_page: 100 })
      const refined = refineChildren(item, res.items)
      if (refined.length > 0) {
        setSubLevels((prev) => {
          const copy = prev.slice(0, levelIndex + 1)
          copy[levelIndex + 1] = refined
          return copy
        })
      } else {
        applyItem(item)
      }
    } catch (e) {
      toastError(tm('ayCategoriesLoadError'), normalizeAxiosError(e))
    } finally {
      setLoading(false)
    }
  }

  const deepest = subChosen.length > 0 ? subChosen[subChosen.length - 1] : null
  const hasDeeperOptions =
    department != null &&
    deepest != null &&
    subLevels.length > subChosen.length &&
    (subLevels[subChosen.length]?.length ?? 0) > 0

  if (rootsError) {
    return <p className="text-xs text-red-600">{tm('ayCategoriesLoadError')}</p>
  }

  if (rootsLoading) {
    return <p className="text-xs text-[var(--color-muted)]">{t('loading')}</p>
  }

  if (!roots.length) {
    return <p className="text-xs text-[var(--color-muted)]">{tm('ayCategoriesEmpty')}</p>
  }

  return (
    <div className="flex min-w-[12rem] flex-col gap-3">
      <div>
        <p className="mb-2 text-xs font-medium text-zinc-700 dark:text-zinc-200">{tm('departmentPickIntro')}</p>
        <div
          className="flex flex-wrap gap-2"
          role="group"
          aria-label={tm('selectDepartmentFirst')}
        >
          {departmentOptions.map((opt) => {
            const selected = department?.id === opt.id
            return (
              <Button
                key={opt.id}
                type="button"
                variant={selected ? 'primary' : 'secondary'}
                disabled={loading}
                className={cn(
                  'min-w-[4.5rem] px-3 py-2 text-xs font-medium',
                  selected && 'ring-2 ring-indigo-400/80 ring-offset-2 ring-offset-[var(--color-surface-elevated)] dark:ring-offset-zinc-900',
                )}
                onClick={() => void handleDepartmentPick(opt)}
              >
                {humanizeDepartmentButtonLabel(opt)}
              </Button>
            )
          })}
        </div>
      </div>

      {department != null && subLevels.length > 0
        ? subLevels.map((options, levelIndex) => {
            const siblingPaths = options.map((o) => o.path || o.name)
            return (
              <select
                key={`sub-${levelIndex}`}
                className="max-w-md rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-xs"
                value={subChosen[levelIndex]?.id ?? ''}
                onChange={(e) => void handleSubLevelChange(levelIndex, e.target.value)}
                disabled={loading}
              >
                <option value="">
                  {levelIndex === 0 ? tm('selectCategoryUnderDepartment') : tm('selectSubCategory')}
                </option>
                {options.map((opt) => {
                  const full = opt.path || opt.name
                  return (
                    <option key={opt.id} value={opt.id}>
                      {formatAyCategoryOptionLabel(full, siblingPaths)}
                    </option>
                  )
                })}
              </select>
            )
          })
        : null}

      {department != null ? (
        <div className="flex flex-wrap items-center gap-2">
          {hasDeeperOptions && deepest != null ? (
            <Button variant="secondary" type="button" className="text-xs" disabled={loading} onClick={() => applyItem(deepest)}>
              {tm('useThisCategory')}
            </Button>
          ) : null}
          <Button variant="ghost" type="button" className="text-xs" disabled={loading} onClick={resetAll}>
            {tm('resetCategoryPickers')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
