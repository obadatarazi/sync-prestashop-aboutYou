import { useMemo, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { normalizeAxiosError } from '@/api/errors'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useProductsQuery } from '@/hooks/useProductsQuery'
import { useSyncProductsMutation } from '@/hooks/useSyncMutation'
import { useTableColumnPrefs } from '@/hooks/useTableColumnPrefs'
import i18n from '@/lib/i18n'
import { toastError, toastSuccess } from '@/store/toastStore'
import { exportRowsToCsv } from '@/utils/csv'
import { exportTablePdf } from '@/utils/pdf'
import { refetchProducts } from '@/services/productsService'
import type { ProductRow, ProductSyncStatus } from '@/types/api'

export const PRODUCT_STATUS_OPTIONS: Array<{ value: 'all' | ProductSyncStatus; labelKey: string }> = [
  { value: 'all', labelKey: 'products.statusAll' },
  { value: 'synced', labelKey: 'products.statusSynced' },
  { value: 'pending', labelKey: 'products.statusPending' },
  { value: 'error', labelKey: 'products.statusFailed' },
  { value: 'syncing', labelKey: 'products.statusRetrying' },
  { value: 'quarantined', labelKey: 'products.statusQuarantined' },
]

export const PRODUCT_COLUMNS = [
  { id: 'ps_id', labelKey: 'products.columns.psId' },
  { id: 'reference', labelKey: 'products.columns.reference' },
  { id: 'name', labelKey: 'products.columns.name' },
  { id: 'sync_status', labelKey: 'products.columns.status' },
  { id: 'ay_style_key', labelKey: 'products.columns.ayStyleKey' },
  { id: 'ay_category_path', labelKey: 'products.columns.ayCategory' },
  { id: 'price', labelKey: 'products.columns.price' },
  { id: 'updated_at', labelKey: 'products.columns.updatedAt' },
] as const

type ColId = (typeof PRODUCT_COLUMNS)[number]['id']
type SortKey = ColId | null
type SortDir = 'asc' | 'desc'
type RefetchMonitorState = {
  status: 'idle' | 'running' | 'success' | 'error'
  startedAt: string | null
  finishedAt: string | null
  requested: number
  updated: number
  notFoundIds: number[]
  errorMessage: string | null
}

function sortRows(rows: ProductRow[], key: SortKey, dir: SortDir): ProductRow[] {
  if (!key) return rows
  const mul = dir === 'asc' ? 1 : -1
  return [...rows].sort((a, b) => {
    const va = a[key]
    const vb = b[key]
    if (va == null && vb == null) return 0
    if (va == null) return 1
    if (vb == null) return -1
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * mul
    return String(va).localeCompare(String(vb)) * mul
  })
}

export function useProductsController() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Math.max(1, Number.parseInt(searchParams.get('page') ?? '1', 10) || 1)
  const perPage = Math.min(200, Math.max(1, Number.parseInt(searchParams.get('per_page') ?? '20', 10) || 20))
  const statusParam = (searchParams.get('status') ?? 'all') as 'all' | ProductSyncStatus
  const status: 'all' | ProductSyncStatus = PRODUCT_STATUS_OPTIONS.some((o) => o.value === statusParam)
    ? statusParam
    : 'all'

  const [search, setSearch] = useState(searchParams.get('search') ?? '')
  const debouncedSearch = useDebouncedValue(search, 300)
  const [sortKey, setSortKey] = useState<SortKey>('ps_id')
  const [sortDir, setSortDir] = useState<SortDir>('asc')
  const [selected, setSelected] = useState<Set<number>>(() => new Set())
  const [refetchMonitor, setRefetchMonitor] = useState<RefetchMonitorState>({
    status: 'idle',
    startedAt: null,
    finishedAt: null,
    requested: 0,
    updated: 0,
    notFoundIds: [],
    errorMessage: null,
  })

  const { visible, toggle } = useTableColumnPrefs('products', PRODUCT_COLUMNS.map((c) => c.id))
  const query = useProductsQuery({
    page,
    per_page: perPage,
    status: status === 'all' ? undefined : status,
    search: debouncedSearch,
  })
  const syncProducts = useSyncProductsMutation()
  const refetchMutation = useMutation({
    mutationFn: async () => {
      const startedAt = new Date().toISOString()
      setRefetchMonitor({
        status: 'running',
        startedAt,
        finishedAt: null,
        requested: 0,
        updated: 0,
        notFoundIds: [],
        errorMessage: null,
      })
      return refetchProducts()
    },
    onSuccess: async (res) => {
      toastSuccess(i18n.t('products.refetchToastTitle'), i18n.t('products.refetchToastDescription', { updated: res.updated }))
      setRefetchMonitor((prev) => ({
        ...prev,
        status: 'success',
        finishedAt: new Date().toISOString(),
        requested: res.requested,
        updated: res.updated,
        notFoundIds: res.not_found_ids ?? [],
        errorMessage: null,
      }))
      await query.refetch()
    },
    onError: (err) => {
      const errorMessage = normalizeAxiosError(err)
      setRefetchMonitor((prev) => ({
        ...prev,
        status: 'error',
        finishedAt: new Date().toISOString(),
        errorMessage,
      }))
      toastError(i18n.t('products.refetchToastError'), errorMessage)
    },
  })
  const sorted = useMemo(() => sortRows(query.data?.rows ?? [], sortKey, sortDir), [query.data?.rows, sortKey, sortDir])

  const setPage = (p: number) => {
    const next = new URLSearchParams(searchParams)
    next.set('page', String(p))
    setSearchParams(next, { replace: true })
  }

  const setStatus = (v: 'all' | ProductSyncStatus) => {
    const next = new URLSearchParams(searchParams)
    if (v === 'all') next.delete('status')
    else next.set('status', v)
    next.set('page', '1')
    setSearchParams(next, { replace: true })
  }

  const toggleSort = (k: ColId) => {
    if (sortKey === k) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    else {
      setSortKey(k)
      setSortDir('asc')
    }
  }

  const toggleRow = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const toggleAllPage = () => {
    if (selected.size === sorted.length) setSelected(new Set())
    else setSelected(new Set(sorted.map((r) => r.id)))
  }

  const selectedPsIds = () => {
    const map = new Map(sorted.map((r) => [r.id, r.ps_id]))
    return [...selected].map((id) => map.get(id)).filter((x): x is number => typeof x === 'number')
  }

  const syncBulk = () => {
    const ids = selectedPsIds()
    if (!ids.length) return
    syncProducts.mutate(
      { ps_product_ids: ids, sync_command: 'products:inc' },
      {
        onSuccess: (res) => {
          toastSuccess('Product sync', res.message ?? 'queued')
          setSelected(new Set())
        },
        onError: (err) => toastError('Sync failed', normalizeAxiosError(err)),
      },
    )
  }

  const syncOne = (psId: number) => {
    syncProducts.mutate(
      { ps_product_ids: [psId], sync_command: 'products:inc' },
      {
        onSuccess: (res) => toastSuccess('Sync', res.message ?? ''),
        onError: (err) => toastError('Sync failed', normalizeAxiosError(err)),
      },
    )
  }

  const exportCsv = () => {
    const headers = ['ps_id', 'reference', 'name', 'sync_status', 'ay_style_key', 'ay_category_path', 'price', 'updated_at']
    const data = sorted.map((r) => [
      String(r.ps_id),
      r.reference,
      r.name,
      r.sync_status,
      r.ay_style_key ?? '',
      r.ay_category_path ?? '',
      String(r.price),
      r.updated_at ?? '',
    ])
    exportRowsToCsv('products', headers, data)
  }

  const exportPdf = () => {
    const headers = ['PS ID', 'Ref', 'Name', 'Status', 'AY', 'AY category', 'Price']
    const data = sorted.map((r) => [
      String(r.ps_id),
      r.reference,
      r.name,
      r.sync_status,
      r.ay_style_key ?? '',
      (r.ay_category_path ?? '').slice(0, 48),
      String(r.price),
    ])
    exportTablePdf('Products', 'products.pdf', headers, data)
  }

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      toastSuccess('Copied')
    } catch {
      toastError('Copy failed')
    }
  }

  return {
    query,
    search,
    setSearch,
    page,
    perPage,
    status,
    setStatus,
    visible,
    toggleColumn: toggle,
    sorted,
    sortKey,
    sortDir,
    toggleSort,
    selected,
    toggleRow,
    toggleAllPage,
    setPage,
    syncProducts,
    refetchMutation,
    refetchMonitor,
    syncBulk,
    syncOne,
    exportCsv,
    exportPdf,
    copy,
    selectedCount: selected.size,
    total: query.data?.total ?? 0,
    hasNextPage: Boolean(query.data && page * perPage < (query.data.total ?? 0)),
  }
}
