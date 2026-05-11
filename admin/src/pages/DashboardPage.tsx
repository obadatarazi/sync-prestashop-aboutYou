import { useQuery } from '@tanstack/react-query'
import { formatDistanceToNow } from 'date-fns'
import { useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { fetchProducts } from '@/api/products'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { JsonViewer } from '@/components/JsonViewer'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { useSyncRunMutation } from '@/hooks/useSyncMutation'
import { normalizeAxiosError } from '@/api/errors'
import { pushProductCountSnapshot, readProductCountHistory } from '@/utils/statusHistory'
import { toastError, toastSuccess } from '@/store/toastStore'
import type { StatusSnapshot } from '@/types/api'
import { cn } from '@/lib/cn'

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium text-[var(--color-muted)]">{label}</p>
        <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
      </CardContent>
    </Card>
  )
}

function MiniSparkline({ values }: { values: number[] }) {
  const { t } = useTranslation('dashboard')
  if (values.length < 2) return <p className="text-xs text-[var(--color-muted)]">{t('collectingSnapshots')}</p>
  const max = Math.max(...values, 1)
  const min = Math.min(...values)
  const range = max - min || 1
  return (
    <div className="flex h-12 items-end gap-px">
      {values.map((v, i) => (
        <div
          key={`${v}-${i}`}
          className="flex-1 rounded-sm bg-[var(--color-accent)]/70"
          style={{ height: `${Math.max(8, ((v - min) / range) * 100)}%` }}
          title={String(v)}
        />
      ))}
    </div>
  )
}

export default function DashboardPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: td } = useTranslation('dashboard')
  const navigate = useNavigate()
  const syncRun = useSyncRunMutation()
  const [statusSnap, setStatusSnap] = useState<StatusSnapshot | null>(null)

  const failedProducts = useQuery({
    queryKey: ['dashboard', 'failed-count'],
    queryFn: ({ signal }) => fetchProducts({ page: 1, per_page: 1, status: 'error', signal }),
  })

  const refreshStatus = () => {
    syncRun.mutate(
      { command: 'status' },
      {
        onSuccess: (res) => {
          const r = res.result as StatusSnapshot | undefined
          if (r) {
            setStatusSnap(r)
            const n = r.database?.products
            if (typeof n === 'number') pushProductCountSnapshot(n)
          }
          toastSuccess(td('statusRefreshed'), res.message ?? '')
        },
        onError: (e) => toastError(td('statusFailed'), normalizeAxiosError(e)),
      },
    )
  }

  const db = statusSnap?.database
  const flags = statusSnap?.flags
  const history = readProductCountHistory()

  const runCmd = (command: 'products' | 'products:inc' | 'stock' | 'orders' | 'order-status' | 'retry' | 'all') => {
    syncRun.mutate(
      { command },
      {
        onSuccess: (res) => toastSuccess('Sync', res.message ?? command),
        onError: (e) => toastError('Sync failed', normalizeAxiosError(e)),
      },
    )
  }

  return (
    <div>
      <Helmet>
        <title>
          {tn('dashboard')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('dashboard') }]} />
      <PageHeader
        title={tn('dashboard')}
        description={td('description')}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" type="button" onClick={refreshStatus} disabled={syncRun.isPending}>
              {td('refreshStatus')}
            </Button>
            <Button variant="secondary" type="button" onClick={() => navigate('/sync')}>
              {td('openSyncControl')}
            </Button>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Stat label={td('products')} value={db?.products ?? '—'} />
        <Stat label={td('orders')} value={db?.orders ?? '—'} />
        <Stat label={td('syncRunsRows')} value={db?.sync_runs ?? '—'} />
        <Stat label={td('failedProducts')} value={failedProducts.data?.total ?? '—'} />
      </div>

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{td('flags')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>
              <span className="text-[var(--color-muted)]">DRY_RUN</span>{' '}
              <span className={cn('font-medium', flags?.dry_run && 'text-amber-700 dark:text-amber-300')}>
                {String(flags?.dry_run ?? '—')}
              </span>
            </p>
            <p>
              <span className="text-[var(--color-muted)]">TEST_MODE</span>{' '}
              <span className={cn('font-medium', flags?.test_mode && 'text-amber-700 dark:text-amber-300')}>
                {String(flags?.test_mode ?? '—')}
              </span>
            </p>
            <p className="text-xs text-[var(--color-muted)]">
              {td('flagsHint')}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{td('productCountTrend')}</CardTitle>
          </CardHeader>
          <CardContent>
            <MiniSparkline values={history} />
          </CardContent>
        </Card>
      </div>

      <div className="mt-6">
        <Card>
          <CardHeader>
            <CardTitle>{td('quickActions')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {(['products', 'products:inc', 'stock', 'orders', 'order-status', 'retry', 'all'] as const).map((c) => (
              <Button key={c} variant="secondary" type="button" disabled={syncRun.isPending} onClick={() => runCmd(c)}>
                {c}
              </Button>
            ))}
          </CardContent>
        </Card>
      </div>

      {statusSnap ? (
        <div className="mt-6">
          <JsonViewer title={td('lastStatusPayload')} value={statusSnap} />
        </div>
      ) : null}

      <div className="mt-6 grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>{td('recentSyncRuns')}</CardTitle>
          </CardHeader>
          <CardContent className="text-sm text-[var(--color-muted)]">
            {td('recentSyncRunsHint')}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{td('recentFailedProducts')}</CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            <Link className="text-[var(--color-accent)] hover:underline" to="/products?status=error">
              {td('viewFailedProducts')}
            </Link>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{td('retryQueue')}</CardTitle>
          </CardHeader>
          <CardContent className="text-sm text-[var(--color-muted)]">
            <Link className="text-[var(--color-accent)] hover:underline" to="/retry">
              {td('openRetryQueue')}
            </Link>
          </CardContent>
        </Card>
      </div>

      {syncRun.isSuccess && syncRun.data?.run_id ? (
        <p className="mt-4 text-xs text-[var(--color-muted)]">
          {td('lastRun', { runId: String(syncRun.data.run_id) })} · {formatDistanceToNow(new Date(), { addSuffix: true })}
        </p>
      ) : null}
    </div>
  )
}
