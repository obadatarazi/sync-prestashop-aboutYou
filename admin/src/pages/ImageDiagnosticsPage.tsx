import { ArrowPathIcon, PhotoIcon } from '@heroicons/react/24/outline'
import { Helmet } from 'react-helmet-async'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { normalizeAxiosError } from '@/api/errors'
import {
  useImageDiagnosticsGalleryQuery,
  useImageDiagnosticsQuery,
  useNormalizeImagesMutation,
} from '@/hooks/useImageDiagnosticsQuery'
import { cn } from '@/lib/cn'
import { toastError, toastSuccess } from '@/store/toastStore'
import type { ImageGalleryImage, ImageGalleryRow } from '@/types/api'

function fmt(n: number | undefined): string {
  if (n === undefined) return '—'
  return n.toLocaleString()
}

export default function ImageDiagnosticsPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: ti } = useTranslation('imagesPage')
  const { t: tu } = useTranslation('ui')
  const [filter, setFilter] = useState<'problematic' | 'all'>('problematic')

  const summaryQ = useImageDiagnosticsQuery(false)
  const galleryQ = useImageDiagnosticsGalleryQuery(filter, 18)
  const normalizeMut = useNormalizeImagesMutation()

  const s = summaryQ.data?.summary
  const rows = galleryQ.data?.rows ?? []
  const normAvailable =
    galleryQ.data?.normalization_available ?? s?.normalization_available ?? false

  const cards = [
    { label: ti('missing'), value: s?.products_missing_usable_images },
    { label: ti('invalidRatio'), value: s?.images_not_ay_ready },
    { label: ti('duplicates'), value: s?.images_duplicate_rows_union },
    { label: ti('failedProcessing'), value: s?.images_failed },
  ]

  const onNormalizeIds = (ids: number[]) => {
    const slice = ids.slice(0, 30)
    if (slice.length === 0) return
    normalizeMut.mutate(
      { product_ids: slice },
      {
        onSuccess: (res) => {
          const tot = res.totals
          toastSuccess(
            ti('normalizeToastTitle'),
            ti('normalizeToastBody', {
              ok: String(tot?.normalized ?? 0),
              fail: String(tot?.failed ?? 0),
              skip: String(tot?.skipped ?? 0),
              err: String(tot?.errors ?? 0),
            }),
          )
        },
        onError: (e) => toastError(ti('normalizeToastError'), normalizeAxiosError(e)),
      },
    )
  }

  return (
    <div>
      <Helmet>
        <title>
          {tn('images')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('images') }]} />
      <PageHeader
        title={tn('images')}
        description={ti('description')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="secondary"
              disabled={summaryQ.isFetching || galleryQ.isFetching}
              onClick={() => {
                void summaryQ.refetch()
                void galleryQ.refetch()
              }}
              className="inline-flex items-center gap-2"
            >
              <ArrowPathIcon
                className={cn('h-4 w-4', (summaryQ.isFetching || galleryQ.isFetching) && 'animate-spin')}
              />
              {ti('refresh')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              disabled={!normAvailable || normalizeMut.isPending || rows.length === 0}
              title={!normAvailable ? ti('normalizationDisabled') : undefined}
              onClick={() => onNormalizeIds(rows.map((r) => r.product.id))}
            >
              {ti('normalizeShown')}
            </Button>
          </div>
        }
      />

      {!normAvailable ? (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">
          {ti('normalizationDisabled')}
        </div>
      ) : null}

      {summaryQ.isError ? (
        <p className="mb-4 text-sm text-red-600">
          {ti('loadError')}{' '}
          <button type="button" className="underline" onClick={() => void summaryQ.refetch()}>
            {tu('tryAgain')}
          </button>
        </p>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {cards.map((c) => (
          <Card key={c.label}>
            <CardContent className="p-4">
              <p className="text-xs text-[var(--color-muted)]">{c.label}</p>
              <p className="mt-1 text-2xl font-semibold tabular-nums">
                {summaryQ.isLoading ? '…' : fmt(c.value)}
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      {s ? (
        <div className="mt-4 space-y-1 text-xs text-[var(--color-muted)]">
          <p>
            {ti('breakdown', {
              noRows: fmt(s.products_without_image_rows),
              noOk: fmt(s.products_with_images_but_no_ok),
            })}
          </p>
          <p>
            {ti('totals', {
              total: fmt(s.image_rows_total),
              ok: fmt(s.images_ok),
              pending: fmt(s.images_pending),
              processing: fmt(s.images_processing),
            })}
          </p>
        </div>
      ) : null}

      <Card className="mt-8">
        <CardHeader className="flex flex-col gap-4 border-b border-[var(--color-border)] pb-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <CardTitle>{ti('galleryTitle')}</CardTitle>
            <p className="mt-1 max-w-2xl text-sm text-[var(--color-muted)]">{ti('gallerySubtitle')}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <div className="inline-flex rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-0.5">
              <button
                type="button"
                onClick={() => setFilter('problematic')}
                className={cn(
                  'rounded-md px-3 py-1.5 text-sm font-medium transition',
                  filter === 'problematic'
                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50'
                    : 'text-[var(--color-muted)] hover:text-zinc-800 dark:hover:text-zinc-200',
                )}
              >
                {ti('filterProblematic')}
              </button>
              <button
                type="button"
                onClick={() => setFilter('all')}
                className={cn(
                  'rounded-md px-3 py-1.5 text-sm font-medium transition',
                  filter === 'all'
                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-zinc-50'
                    : 'text-[var(--color-muted)] hover:text-zinc-800 dark:hover:text-zinc-200',
                )}
              >
                {ti('filterAll')}
              </button>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-6 pt-6">
          {galleryQ.isError ? (
            <p className="text-sm text-red-600">
              {ti('galleryLoadError')}{' '}
              <button type="button" className="underline" onClick={() => void galleryQ.refetch()}>
                {tu('tryAgain')}
              </button>
            </p>
          ) : galleryQ.isLoading ? (
            <div className="grid gap-4 sm:grid-cols-2">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="h-48 animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800/80" />
              ))}
            </div>
          ) : rows.length === 0 ? (
            <p className="text-sm text-[var(--color-muted)]">{ti('galleryEmpty')}</p>
          ) : (
            <div className="grid gap-5 lg:grid-cols-2">
              {rows.map((row) => (
                <ProductImageGroupCard
                  key={row.product.id}
                  row={row}
                  normAvailable={normAvailable}
                  normalizePending={normalizeMut.isPending}
                  onNormalize={() => onNormalizeIds([row.product.id])}
                />
              ))}
            </div>
          )}

          <p className="border-t border-[var(--color-border)] pt-4 text-sm text-[var(--color-muted)]">
            {ti('galleryHint')}{' '}
            <Link className="text-[var(--color-accent)] hover:underline" to="/products">
              {ti('goToProducts')}
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  )
}

function ProductImageGroupCard(props: {
  row: ImageGalleryRow
  normAvailable: boolean
  normalizePending: boolean
  onNormalize: () => void
}) {
  const { t: ti } = useTranslation('imagesPage')
  const { row, normAvailable, normalizePending, onNormalize } = props
  const { product, images, flags } = row
  const title = product.name?.trim() || product.reference || `Product #${product.id}`

  return (
    <div className="flex flex-col overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--color-border)] bg-zinc-50/80 px-4 py-3 dark:bg-zinc-900/40">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="truncate font-medium text-zinc-900 dark:text-zinc-50">{title}</h3>
            <Link
              to={`/products/${product.id}`}
              className="shrink-0 text-xs font-medium text-[var(--color-accent)] hover:underline"
            >
              PS #{product.ps_id} · {ti('open')}
            </Link>
          </div>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {flags.no_images ? (
              <Badge tone="warning">{ti('badgeNoImages')}</Badge>
            ) : null}
            {flags.has_error ? <Badge tone="danger">{ti('badgeError')}</Badge> : null}
            {flags.has_pending ? <Badge tone="info">{ti('badgePending')}</Badge> : null}
            {flags.not_ay_ready ? <Badge tone="warning">{ti('badgeNotAyReady')}</Badge> : null}
            {!flags.needs_attention && !flags.no_images ? (
              <Badge tone="success">{ti('badgeHealthy')}</Badge>
            ) : null}
          </div>
        </div>
        <Button
          type="button"
          variant="secondary"
          className="shrink-0"
          disabled={!normAvailable || normalizePending}
          title={!normAvailable ? ti('normalizationDisabled') : undefined}
          onClick={onNormalize}
        >
          {ti('normalizeOne')}
        </Button>
      </div>

      <div className="p-3">
        {images.length === 0 ? (
          <div className="flex h-36 items-center justify-center gap-2 rounded-lg border border-dashed border-[var(--color-border)] text-sm text-[var(--color-muted)]">
            <PhotoIcon className="h-8 w-8 opacity-50" />
            <span>{ti('noThumbnails')}</span>
          </div>
        ) : (
          <div className="flex gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:thin]">
            {images.map((im) => (
              <ImageThumb key={im.id} im={im} />
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function ImageThumb(props: { im: ImageGalleryImage }) {
  const { t: ti } = useTranslation('imagesPage')
  const { im } = props
  const url = im.public_url?.trim()
  const st = (im.status || '').toLowerCase()
  const tone =
    st === 'error' ? 'danger' : st === 'pending' || st === 'processing' ? 'info' : st === 'ok' ? 'success' : 'default'

  return (
    <div className="group relative w-[5.5rem] shrink-0">
      <div className="relative aspect-[3/4] w-full overflow-hidden rounded-lg border border-[var(--color-border)] bg-zinc-100 dark:bg-zinc-900">
        {url ? (
          <a href={url} target="_blank" rel="noreferrer" className="block h-full w-full">
            <img src={url} alt="" className="h-full w-full object-cover transition group-hover:opacity-95" loading="lazy" />
          </a>
        ) : (
          <div className="flex h-full w-full items-center justify-center p-1">
            <PhotoIcon className="h-8 w-8 text-zinc-400" />
          </div>
        )}
        <span className="absolute bottom-1 left-1 right-1 flex justify-center">
          <span
            className={cn(
              'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide shadow-sm',
              tone === 'danger' && 'bg-red-600 text-white',
              tone === 'info' && 'bg-sky-600 text-white',
              tone === 'success' && 'bg-emerald-700 text-white',
              tone === 'default' && 'bg-zinc-800/85 text-white',
            )}
          >
            {im.status || '—'}
          </span>
        </span>
      </div>
      {im.width != null && im.height != null ? (
        <p className="mt-1 truncate text-center text-[10px] text-[var(--color-muted)] tabular-nums">
          {im.width}×{im.height}
        </p>
      ) : (
        <p className="mt-1 truncate text-center text-[10px] text-[var(--color-muted)]">{ti('dimUnknown')}</p>
      )}
      {im.error_message ? (
        <p className="mt-0.5 line-clamp-2 text-[10px] text-red-600" title={im.error_message}>
          {im.error_message}
        </p>
      ) : null}
    </div>
  )
}
