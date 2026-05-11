import { useMemo, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { normalizeAxiosError } from '@/api/errors'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useOrdersQuery } from '@/hooks/useOrdersQuery'
import i18n from '@/lib/i18n'
import { toastError, toastSuccess } from '@/store/toastStore'
import { refetchOrders } from '@/services/ordersService'
import { exportRowsToCsv } from '@/utils/csv'
import type { OrderRow } from '@/types/api'

function clientFilter(rows: OrderRow[], q: string): OrderRow[] {
  const s = q.trim().toLowerCase()
  if (!s) return rows
  return rows.filter(
    (r) =>
      r.ay_order_id.toLowerCase().includes(s) ||
      String(r.ps_order_id ?? '').includes(s) ||
      r.sync_status.toLowerCase().includes(s),
  )
}

type RefetchMonitorState = {
  status: 'idle' | 'running' | 'success' | 'error'
  startedAt: string | null
  finishedAt: string | null
  requested: number
  updated: number
  failed: number
  failedIds: string[]
  errorMessage: string | null
}

export function useOrdersController() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Math.max(1, Number.parseInt(searchParams.get('page') ?? '1', 10) || 1)
  const perPage = Math.min(200, Math.max(1, Number.parseInt(searchParams.get('per_page') ?? '20', 10) || 20))
  const [search, setSearch] = useState(searchParams.get('search') ?? '')
  const [refetchSince, setRefetchSince] = useState('')
  const [refetchMonitor, setRefetchMonitor] = useState<RefetchMonitorState>({
    status: 'idle',
    startedAt: null,
    finishedAt: null,
    requested: 0,
    updated: 0,
    failed: 0,
    failedIds: [],
    errorMessage: null,
  })
  const debounced = useDebouncedValue(search, 300)
  const query = useOrdersQuery({ page, per_page: perPage })
  const filtered = useMemo(() => clientFilter(query.data?.rows ?? [], debounced), [query.data?.rows, debounced])
  const refetchMutation = useMutation({
    mutationFn: async () => {
      const startedAt = new Date().toISOString()
      setRefetchMonitor({
        status: 'running',
        startedAt,
        finishedAt: null,
        requested: 0,
        updated: 0,
        failed: 0,
        failedIds: [],
        errorMessage: null,
      })
      return refetchOrders({ since: refetchSince.trim() || undefined })
    },
    onSuccess: async (res) => {
      setRefetchMonitor((prev) => ({
        ...prev,
        status: 'success',
        finishedAt: new Date().toISOString(),
        requested: res.requested,
        updated: res.updated,
        failed: res.failed,
        failedIds: res.failed_ids ?? [],
        errorMessage: null,
      }))
      toastSuccess(i18n.t('orders.refetchToastTitle'), i18n.t('orders.refetchToastDescription', { updated: res.updated }))
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
      toastError(i18n.t('orders.refetchToastError'), errorMessage)
    },
  })

  const setPage = (p: number) => {
    const next = new URLSearchParams(searchParams)
    next.set('page', String(p))
    setSearchParams(next, { replace: true })
  }

  const exportCsv = () => {
    const headers = ['ay_order_id', 'ps_order_id', 'sync_status', 'ay_status', 'total_paid', 'created_at']
    const data = filtered.map((r) => [
      r.ay_order_id,
      String(r.ps_order_id ?? ''),
      r.sync_status,
      r.ay_status ?? '',
      String(r.total_paid),
      r.created_at ?? '',
    ])
    exportRowsToCsv('orders', headers, data)
  }

  return {
    query,
    filtered,
    page,
    perPage,
    search,
    setSearch,
    refetchSince,
    setRefetchSince,
    refetchMutation,
    refetchMonitor,
    setPage,
    exportCsv,
    total: query.data?.total ?? 0,
    hasNextPage: Boolean(query.data && page * perPage < (query.data.total ?? 0)),
  }
}
