import { format } from 'date-fns'
import { ArrowPathIcon } from '@heroicons/react/24/outline'
import { useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { OrderStatusBadge } from '@/components/StatusBadge'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { useOrdersController } from '@/features/orders/hooks/useOrdersController'
import { cn } from '@/lib/cn'

export default function OrdersPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('orders')
  const c = useOrdersController()
  const [showRefetchConfirm, setShowRefetchConfirm] = useState(false)
  const monitor = c.refetchMonitor
  const durationMs =
    monitor.startedAt && monitor.finishedAt
      ? Math.max(0, new Date(monitor.finishedAt).getTime() - new Date(monitor.startedAt).getTime())
      : null

  return (
    <div>
      <Helmet>
        <title>
          {tn('title')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('title') }]} />
      <PageHeader
        title={tn('title')}
        description={tn('description')}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" type="button" onClick={() => c.query.refetch()} disabled={c.query.isFetching}>
              {t('refresh')}
            </Button>
            <Button variant="secondary" type="button" onClick={c.exportCsv}>
              {t('exportCsv')}
            </Button>
            <Button variant="secondary" type="button" onClick={() => setShowRefetchConfirm(true)} disabled={c.refetchMutation.isPending}>
              <ArrowPathIcon className={cn('h-4 w-4', c.refetchMutation.isPending && 'animate-spin')} aria-hidden />
              {tn('refetchFromAboutYou')}
            </Button>
          </div>
        }
      />

      <Card className="mb-4">
        <CardContent className="p-4">
          <div className="grid gap-3 md:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{tn('clientFilter')}</label>
              <Input value={c.search} onChange={(e) => c.setSearch(e.target.value)} placeholder={tn('searchPlaceholder')} />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{tn('refetchSinceLabel')}</label>
              <Input
                value={c.refetchSince}
                onChange={(e) => c.setRefetchSince(e.target.value)}
                placeholder={tn('refetchSincePlaceholder')}
              />
            </div>
          </div>
        </CardContent>
      </Card>
      {monitor.status !== 'idle' ? (
        <Card className="mb-4">
          <CardContent className="space-y-3 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-semibold">{tn('refetchMonitorTitle')}</p>
              <span
                className={cn(
                  'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium',
                  monitor.status === 'running' && 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
                  monitor.status === 'success' && 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
                  monitor.status === 'error' && 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
                )}
              >
                {tn(`refetchStatus.${monitor.status}`)}
              </span>
            </div>
            <div className="grid gap-2 text-sm text-[var(--color-muted)] sm:grid-cols-2 lg:grid-cols-4">
              <p>
                {tn('refetchStartedAt')}: {monitor.startedAt ? format(new Date(monitor.startedAt), 'yyyy-MM-dd HH:mm:ss') : '—'}
              </p>
              <p>
                {tn('refetchFinishedAt')}:{' '}
                {monitor.finishedAt ? format(new Date(monitor.finishedAt), 'yyyy-MM-dd HH:mm:ss') : tn('refetchStillRunning')}
              </p>
              <p>
                {tn('refetchRequested')}: {monitor.requested}
              </p>
              <p>
                {tn('refetchUpdated')}: {monitor.updated}
              </p>
            </div>
            <div className="grid gap-2 text-sm text-[var(--color-muted)] sm:grid-cols-2">
              <p>
                {tn('refetchFailed')}: {monitor.failed}
              </p>
              {durationMs != null ? (
                <p>
                  {tn('refetchDuration')}: {(durationMs / 1000).toFixed(2)}s
                </p>
              ) : null}
            </div>
            {monitor.failedIds.length > 0 ? (
              <p className="text-sm text-amber-600 dark:text-amber-400">
                {tn('refetchFailedIds')}: {monitor.failedIds.join(', ')}
              </p>
            ) : null}
            {monitor.errorMessage ? <p className="text-sm text-red-600">{monitor.errorMessage}</p> : null}
          </CardContent>
        </Card>
      ) : null}

      <div className="table-shell">
        <table className="table-ui">
          <thead>
            <tr>
              <th className="px-3 py-3">{tn('columns.ayOrder')}</th>
              <th className="px-3 py-3">{tn('columns.psOrder')}</th>
              <th className="px-3 py-3">{tn('columns.sync')}</th>
              <th className="px-3 py-3">{tn('columns.ayStatus')}</th>
              <th className="px-3 py-3 text-right">{tn('columns.total')}</th>
              <th className="px-3 py-3">{tn('columns.created')}</th>
              <th className="px-3 py-3 text-right">{t('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {c.query.isLoading ? (
              Array.from({ length: 6 }).map((_, i) => (
                <tr key={i}>
                  <td colSpan={7} className="px-3 py-3">
                    <div className="h-4 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
                  </td>
                </tr>
              ))
            ) : c.filtered.length === 0 ? (
              <tr>
                <td colSpan={7} className="table-empty">
                  {tn('description')}
                </td>
              </tr>
            ) : (
              c.filtered.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2 font-mono text-xs">{r.ay_order_id}</td>
                  <td className="px-3 py-2">{r.ps_order_id ?? '—'}</td>
                  <td className="px-3 py-2">
                    <OrderStatusBadge status={r.sync_status} />
                  </td>
                  <td className="px-3 py-2 text-xs">{r.ay_status ?? '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{r.total_paid.toFixed(2)}</td>
                  <td className="px-3 py-2 text-xs text-[var(--color-muted)]">
                    {r.created_at ? format(new Date(r.created_at), 'yyyy-MM-dd HH:mm') : '—'}
                  </td>
                  <td className="px-3 py-2 text-right">
                    <Link to={`/orders/${r.id}`} className="text-[var(--color-accent)] hover:underline">
                      {t('view')}
                    </Link>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-[var(--color-muted)]">
        <p>
          {tn('pagination', { page: c.page, total: c.total })}
        </p>
        <div className="flex gap-2">
          <Button variant="secondary" type="button" disabled={c.page <= 1} onClick={() => c.setPage(c.page - 1)}>
            {t('previous')}
          </Button>
          <Button
            variant="secondary"
            type="button"
            disabled={!c.hasNextPage}
            onClick={() => c.setPage(c.page + 1)}
          >
            {t('next')}
          </Button>
        </div>
      </div>
      <ConfirmDialog
        open={showRefetchConfirm}
        onClose={() => {
          if (!c.refetchMutation.isPending) setShowRefetchConfirm(false)
        }}
        onConfirm={() => {
          c.refetchMutation.mutate(undefined, {
            onSettled: () => setShowRefetchConfirm(false),
          })
        }}
        danger
        loading={c.refetchMutation.isPending}
        loadingLabel={tn('refetchInProgress')}
        title={tn('refetchConfirmTitle')}
        description={tn('refetchConfirmDescription')}
        confirmLabel={tn('refetchConfirmAction')}
        cancelLabel={t('cancel')}
      />
    </div>
  )
}
