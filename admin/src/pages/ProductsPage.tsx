import { Listbox, ListboxButton, ListboxOption, ListboxOptions, Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'
import { ChevronUpDownIcon, EllipsisVerticalIcon } from '@heroicons/react/20/solid'
import { ArrowPathIcon } from '@heroicons/react/24/outline'
import { format } from 'date-fns'
import { useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { ProductStatusBadge } from '@/components/StatusBadge'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { ProductAyCategoryMapDialog } from '@/components/ProductAyCategoryMapDialog'
import { PRODUCT_COLUMNS, PRODUCT_STATUS_OPTIONS, useProductsController } from '@/features/products/hooks/useProductsController'
import { useAyCategoryRootsQuery } from '@/hooks/useMappingsQuery'
import { cn } from '@/lib/cn'

export default function ProductsPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('products')
  const navigate = useNavigate()
  const c = useProductsController()
  const ayRootsQuery = useAyCategoryRootsQuery()
  const [ayMapProductId, setAyMapProductId] = useState<number | null>(null)
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
          {tn('products')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('products') }]} />
      <PageHeader
        title={tn('title')}
        description={tn('description')}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              type="button"
              onClick={() => {
                void c.query.refetch()
                void ayRootsQuery.refetch()
              }}
              disabled={c.query.isFetching || ayRootsQuery.isFetching}
            >
              {t('refresh')}
            </Button>
            <Button variant="secondary" type="button" onClick={c.exportCsv}>
              {t('exportCsv')}
            </Button>
            <Button variant="secondary" type="button" onClick={c.exportPdf}>
              {t('exportPdf')}
            </Button>
            <Button
              variant="secondary"
              type="button"
              onClick={() => setShowRefetchConfirm(true)}
              disabled={c.refetchMutation.isPending}
            >
              <ArrowPathIcon className={cn('h-4 w-4', c.refetchMutation.isPending && 'animate-spin')} aria-hidden />
              {tn('refetchFromPrestaShop')}
            </Button>
            <Button type="button" disabled={!c.selectedCount || c.syncProducts.isPending} onClick={c.syncBulk}>
              {tn('syncSelected')} ({c.selectedCount})
            </Button>
          </div>
        }
      />

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-end">
          <div className="min-w-[200px] flex-1">
            <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{t('search')}</label>
            <Input value={c.search} onChange={(e) => c.setSearch(e.target.value)} placeholder={tn('searchPlaceholder')} />
          </div>
          <div className="w-full sm:w-56">
            <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{t('status')}</label>
            <Listbox value={c.status} onChange={c.setStatus}>
              <div className="relative">
                <ListboxButton className="relative w-full cursor-pointer rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] py-2 pr-10 pl-3 text-left text-sm">
                  <span className="block truncate">
                    {t(
                      PRODUCT_STATUS_OPTIONS.find((o) => o.value === c.status)?.labelKey ?? 'products.statusAll',
                      { ns: 'products' },
                    )}
                  </span>
                  <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2">
                    <ChevronUpDownIcon className="h-5 w-5 text-[var(--color-muted)]" aria-hidden />
                  </span>
                </ListboxButton>
                <ListboxOptions className="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] py-1 shadow-lg focus:outline-none">
                  {PRODUCT_STATUS_OPTIONS.map((o) => (
                    <ListboxOption
                      key={o.value}
                      value={o.value}
                      className="cursor-pointer px-3 py-2 text-sm data-[focus]:bg-zinc-100 dark:data-[focus]:bg-zinc-800"
                    >
                      {t(o.labelKey, { ns: 'products' })}
                    </ListboxOption>
                  ))}
                </ListboxOptions>
              </div>
            </Listbox>
          </div>
          <Menu as="div" className="relative">
            <MenuButton className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm data-[hover]:bg-zinc-50 dark:data-[hover]:bg-zinc-800">
              {t('columns')}
            </MenuButton>
            <MenuItems className="absolute right-0 z-20 mt-1 w-52 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] py-1 shadow-lg">
              {PRODUCT_COLUMNS.map((col) => (
                <MenuItem key={col.id}>
                  {({ focus }) => (
                    <label
                      className={cn(
                        'flex cursor-pointer items-center gap-2 px-3 py-2 text-sm',
                        focus && 'bg-zinc-100 dark:bg-zinc-800',
                      )}
                    >
                      <input
                        type="checkbox"
                        checked={c.visible.includes(col.id)}
                        onChange={() => c.toggleColumn(col.id)}
                      />
                      {t(col.labelKey, { ns: 'products' })}
                    </label>
                  )}
                </MenuItem>
              ))}
            </MenuItems>
          </Menu>
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
            {durationMs != null ? (
              <p className="text-sm text-[var(--color-muted)]">
                {tn('refetchDuration')}: {(durationMs / 1000).toFixed(2)}s
              </p>
            ) : null}
            {monitor.notFoundIds.length > 0 ? (
              <p className="text-sm text-amber-600 dark:text-amber-400">
                {tn('refetchNotFound')}: {monitor.notFoundIds.join(', ')}
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
              <th className="w-10 px-3 py-3">
                <input type="checkbox" checked={c.sorted.length > 0 && c.selectedCount === c.sorted.length} onChange={c.toggleAllPage} />
              </th>
              {c.visible.includes('ps_id') ? (
                <th className="px-3 py-3">
                  <button type="button" className="inline-flex items-center gap-1" onClick={() => c.toggleSort('ps_id')}>
                    {tn('columns.psId')} {c.sortKey === 'ps_id' ? (c.sortDir === 'asc' ? '↑' : '↓') : ''}
                  </button>
                </th>
              ) : null}
              {c.visible.includes('reference') ? (
                <th className="px-3 py-3">
                  <button type="button" className="inline-flex items-center gap-1" onClick={() => c.toggleSort('reference')}>
                    {tn('columns.reference')} {c.sortKey === 'reference' ? (c.sortDir === 'asc' ? '↑' : '↓') : ''}
                  </button>
                </th>
              ) : null}
              {c.visible.includes('name') ? (
                <th className="px-3 py-3">
                  <button type="button" className="inline-flex items-center gap-1" onClick={() => c.toggleSort('name')}>
                    {tn('columns.name')} {c.sortKey === 'name' ? (c.sortDir === 'asc' ? '↑' : '↓') : ''}
                  </button>
                </th>
              ) : null}
              {c.visible.includes('sync_status') ? <th className="px-3 py-3">{tn('columns.status')}</th> : null}
              {c.visible.includes('ay_style_key') ? <th className="px-3 py-3">{tn('columns.ayStyleKey')}</th> : null}
              {c.visible.includes('ay_category_path') ? (
                <th className="px-3 py-3">
                  <button type="button" className="inline-flex items-center gap-1" onClick={() => c.toggleSort('ay_category_path')}>
                    {tn('columns.ayCategory')} {c.sortKey === 'ay_category_path' ? (c.sortDir === 'asc' ? '↑' : '↓') : ''}
                  </button>
                </th>
              ) : null}
              {c.visible.includes('price') ? (
                <th className="px-3 py-3 text-right">
                  <button type="button" className="inline-flex items-center gap-1" onClick={() => c.toggleSort('price')}>
                    {tn('columns.price')} {c.sortKey === 'price' ? (c.sortDir === 'asc' ? '↑' : '↓') : ''}
                  </button>
                </th>
              ) : null}
              {c.visible.includes('updated_at') ? <th className="px-3 py-3">{tn('columns.updatedAt')}</th> : null}
              <th className="px-3 py-3 text-right">{t('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {c.query.isLoading ? (
              Array.from({ length: 8 }).map((_, i) => (
                <tr key={i}>
                  <td colSpan={12} className="px-3 py-3">
                    <div className="h-4 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
                  </td>
                </tr>
              ))
            ) : c.sorted.length === 0 ? (
              <tr>
                <td colSpan={12} className="table-empty">
                  {tn('description')}
                </td>
              </tr>
            ) : (
              c.sorted.map((r) => (
                <tr key={r.id}>
                  <td className="px-3 py-2">
                    <input type="checkbox" checked={c.selected.has(r.id)} onChange={() => c.toggleRow(r.id)} />
                  </td>
                  {c.visible.includes('ps_id') ? <td className="px-3 py-2 font-mono text-xs">{r.ps_id}</td> : null}
                  {c.visible.includes('reference') ? <td className="px-3 py-2">{r.reference}</td> : null}
                  {c.visible.includes('name') ? (
                    <td className="max-w-[240px] truncate px-3 py-2" title={r.name}>
                      {r.name}
                    </td>
                  ) : null}
                  {c.visible.includes('sync_status') ? (
                    <td className="px-3 py-2">
                      <ProductStatusBadge status={r.sync_status} />
                    </td>
                  ) : null}
                  {c.visible.includes('ay_style_key') ? (
                    <td className="max-w-[160px] truncate px-3 py-2 font-mono text-xs">{r.ay_style_key ?? '—'}</td>
                  ) : null}
                  {c.visible.includes('ay_category_path') ? (
                    <td className="max-w-[220px] px-3 py-2">
                      <button
                        type="button"
                        className="block max-w-full truncate text-left text-sm text-indigo-600 hover:underline dark:text-indigo-400"
                        title={tn('ayCategoryClickToMap')}
                        onClick={() => setAyMapProductId(r.id)}
                      >
                        {r.ay_category_path?.trim()
                          ? r.ay_category_path
                          : r.ay_category_id
                            ? `#${r.ay_category_id}`
                            : tn('ayCategoryNotMapped')}
                      </button>
                    </td>
                  ) : null}
                  {c.visible.includes('price') ? (
                    <td className="px-3 py-2 text-right tabular-nums">{r.price.toFixed(2)}</td>
                  ) : null}
                  {c.visible.includes('updated_at') ? (
                    <td className="px-3 py-2 text-xs text-[var(--color-muted)]">
                      {r.updated_at ? format(new Date(r.updated_at), 'yyyy-MM-dd HH:mm') : '—'}
                    </td>
                  ) : null}
                  <td className="px-3 py-2 text-right">
                    <Menu as="div" className="relative inline-block text-left">
                      <MenuButton className="rounded p-1 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Row actions">
                        <EllipsisVerticalIcon className="h-5 w-5" />
                      </MenuButton>
                      <MenuItems className="absolute right-0 z-20 mt-1 w-44 origin-top-right rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] py-1 shadow-lg">
                        <MenuItem>
                          {({ focus }) => (
                            <button
                              type="button"
                              className={cn('block w-full px-3 py-2 text-left text-sm', focus && 'bg-zinc-100 dark:bg-zinc-800')}
                              onClick={() => navigate(`/products/${r.id}`)}
                            >
                              {tn('viewDetails')}
                            </button>
                          )}
                        </MenuItem>
                        <MenuItem>
                          {({ focus }) => (
                            <button
                              type="button"
                              className={cn('block w-full px-3 py-2 text-left text-sm', focus && 'bg-zinc-100 dark:bg-zinc-800')}
                              onClick={() =>
                                c.syncOne(r.ps_id)
                              }
                            >
                              {tn('syncRow')}
                            </button>
                          )}
                        </MenuItem>
                        <MenuItem>
                          {({ focus }) => (
                            <button
                              type="button"
                              className={cn('block w-full px-3 py-2 text-left text-sm', focus && 'bg-zinc-100 dark:bg-zinc-800')}
                              onClick={() => c.copy(String(r.ps_id))}
                            >
                              {tn('copyPsId')}
                            </button>
                          )}
                        </MenuItem>
                      </MenuItems>
                    </Menu>
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
      {ayMapProductId != null ? (
        <ProductAyCategoryMapDialog
          open
          onClose={() => setAyMapProductId(null)}
          productId={ayMapProductId}
          roots={ayRootsQuery.data?.items ?? []}
          rootsLoading={ayRootsQuery.isLoading || ayRootsQuery.isFetching}
          rootsError={ayRootsQuery.isError}
        />
      ) : null}
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
