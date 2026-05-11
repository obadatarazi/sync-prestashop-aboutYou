import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react'
import type { ReactNode } from 'react'
import { format } from 'date-fns'
import { useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import { ProductAyCategoryMapDialog } from '@/components/ProductAyCategoryMapDialog'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { JsonViewer } from '@/components/JsonViewer'
import { PageHeader } from '@/components/PageHeader'
import { ProductStatusBadge } from '@/components/StatusBadge'
import { AyCategoryCascadePicker } from '@/components/AyCategoryCascadePicker'
import { Button } from '@/components/ui/Button'
import { Card, CardContent } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { useAyCategoryRootsQuery } from '@/hooks/useMappingsQuery'
import { useProductQuery } from '@/hooks/useProductQuery'
import { useSyncProductsMutation } from '@/hooks/useSyncMutation'
import { normalizeAxiosError } from '@/api/errors'
import { getProductPayloadPreview, saveProductDraft } from '@/services/productsService'
import { toastError, toastSuccess } from '@/store/toastStore'
import type { AyCategorySearchItem, ProductDetailResponse, ProductSyncErrorRow } from '@/types/api'
import { cn } from '@/lib/cn'

export default function ProductDetailPage() {
  const { id } = useParams()
  const pid = Number.parseInt(id ?? '', 10)
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: tp } = useTranslation('productDetail')
  const navigate = useNavigate()
  const q = useProductQuery(Number.isFinite(pid) ? pid : undefined)
  const ayRootsQuery = useAyCategoryRootsQuery()
  const syncProducts = useSyncProductsMutation()
  const [ayCategoryDialogOpen, setAyCategoryDialogOpen] = useState(false)

  const data = q.data as ProductDetailResponse | undefined
  const product = data?.product
  const variants = data?.variants ?? []
  const images = data?.images ?? []
  const syncErrors: ProductSyncErrorRow[] = data?.sync_errors ?? []
  const [previewReady, setPreviewReady] = useState<boolean | null>(null)

  const copyJson = async (obj: unknown) => {
    try {
      await navigator.clipboard.writeText(JSON.stringify(obj, null, 2))
      toastSuccess(t('copied'))
    } catch {
      toastError(t('errors.generic', { ns: 'errors' }))
    }
  }

  const runSync = () => {
    if (!product) return
    if (previewReady === false) {
      toastError(tp('fixPayloadBeforeSync'))
      return
    }
    syncProducts.mutate(
      { ps_product_ids: [product.ps_id], sync_command: 'products:inc' },
      {
        onSuccess: (res) => toastSuccess(t('sync'), res.message ?? ''),
        onError: (e) => toastError(t('errors.generic', { ns: 'errors' }), normalizeAxiosError(e)),
        onSettled: () => void q.refetch(),
      },
    )
  }

  if (q.isError) {
    return (
      <div className="p-6">
        <p className="text-sm text-red-600">{normalizeAxiosError(q.error)}</p>
        <Button className="mt-4" variant="secondary" type="button" onClick={() => navigate('/products')}>
          {tp('backToProducts')}
        </Button>
      </div>
    )
  }

  if (!product && q.isLoading) {
    return (
      <div className="space-y-4 p-4">
        <div className="h-8 w-64 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
        <div className="h-40 animate-pulse rounded-xl bg-zinc-200 dark:bg-zinc-800" />
      </div>
    )
  }

  if (!product) return null

  const tabs = [tp('overview'), tp('variants'), tp('images'), tp('logs'), tp('payload')] as const

  return (
    <div>
      <Helmet>
        <title>
          {product.name} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('products'), to: '/products' }, { label: product.reference }]} />
      <PageHeader
        title={product.name}
        description={`PS #${product.ps_id} · ${product.reference}`}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              type="button"
              onClick={() => {
                void q.refetch()
                void ayRootsQuery.refetch()
              }}
              disabled={q.isFetching || ayRootsQuery.isFetching}
            >
              {t('refresh')}
            </Button>
            <Button type="button" onClick={runSync} disabled={syncProducts.isPending}>
              {tp('syncNow')}
            </Button>
          </div>
        }
      />

      <TabGroup>
        <TabList className="flex gap-1 overflow-x-auto border-b border-[var(--color-border)]">
          {tabs.map((tab) => (
            <Tab
              key={tab}
              className={({ selected }) =>
                cn(
                  'whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium outline-none transition',
                  selected
                    ? 'border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-50'
                    : 'border-transparent text-[var(--color-muted)] hover:text-zinc-800 dark:hover:text-zinc-200',
                )
              }
            >
              {tab}
            </Tab>
          ))}
        </TabList>
        <TabPanels className="mt-4">
          <TabPanel>
            <div className="grid gap-4 md:grid-cols-2">
              <Card>
                <CardContent className="space-y-2 p-4 text-sm">
                  <Row k={tp('psId')} v={String(product.ps_id)} />
                  <Row k={tp('reference')} v={product.reference} />
                  <Row k={tp('ayStyleKey')} v={product.ay_style_key ?? '—'} />
                  <Row
                    k={tp('ayCategory')}
                    v={
                      <div className="flex max-w-[min(100%,20rem)] flex-col items-end gap-1 text-right">
                        <span className="break-words font-normal">
                          {product.ay_category_path?.trim()
                            ? product.ay_category_path
                            : product.ay_category_id
                              ? `#${product.ay_category_id}`
                              : '—'}
                        </span>
                        <Button variant="secondary" type="button" className="shrink-0 text-xs" onClick={() => setAyCategoryDialogOpen(true)}>
                          {tp('mapAyCategory')}
                        </Button>
                      </div>
                    }
                  />
                  <Row k={tp('price')} v={product.price.toFixed(2)} />
                  <Row k={tp('status')} v={<ProductStatusBadge status={product.sync_status} />} />
                  <Row k={tp('updated')} v={product.updated_at ? format(new Date(product.updated_at), 'PPpp') : '—'} />
                  <Row k={tp('lastError')} v={product.sync_error ?? '—'} />
                </CardContent>
              </Card>
            </div>
          </TabPanel>
          <TabPanel>
            <div className="overflow-x-auto rounded-xl border border-[var(--color-border)]">
              <table className="min-w-full text-sm">
                <thead className="bg-zinc-50 text-left text-xs uppercase text-[var(--color-muted)] dark:bg-zinc-900/50">
                  <tr>
                    <th className="px-3 py-2">{t('sku')}</th>
                    <th className="px-3 py-2">{tp('qty')}</th>
                    <th className="px-3 py-2">{tp('ayPushed')}</th>
                  </tr>
                </thead>
                <tbody>
                  {variants.length === 0 ? (
                    <tr>
                      <td colSpan={3} className="px-3 py-8 text-center text-[var(--color-muted)]">
                        {t('noData')}
                      </td>
                    </tr>
                  ) : (
                    variants.map((v) => (
                      <tr key={v.id} className="border-t border-[var(--color-border)]">
                        <td className="px-3 py-2 font-mono text-xs">{v.sku ?? '—'}</td>
                        <td className="px-3 py-2">{v.quantity}</td>
                        <td className="px-3 py-2">{v.ay_pushed ? tp('yes') : tp('no')}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </TabPanel>
          <TabPanel>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {images.length === 0 ? (
                <p className="text-sm text-[var(--color-muted)]">{t('noData')}</p>
              ) : (
                images.map((im) => (
                  <Card key={im.id}>
                    <CardContent className="p-3 text-xs">
                      {im.public_url ? (
                        <a href={im.public_url} target="_blank" rel="noreferrer" className="block">
                          <img src={im.public_url} alt="" className="h-40 w-full rounded-lg object-cover" />
                        </a>
                      ) : (
                        <div className="flex h-40 items-center justify-center rounded-lg bg-zinc-100 text-[var(--color-muted)] dark:bg-zinc-900">
                          {tp('noPreview')}
                        </div>
                      )}
                      <p className="mt-2 font-mono">{im.status ?? '—'}</p>
                      {im.error_message ? <p className="text-red-600">{im.error_message}</p> : null}
                    </CardContent>
                  </Card>
                ))
              )}
            </div>
          </TabPanel>
          <TabPanel>
            {syncErrors.length === 0 ? (
              <p className="text-sm text-[var(--color-muted)]">
                {tp('errorHistoryMissing', { error: product.sync_error ?? '—' })}
              </p>
            ) : (
              <ul className="space-y-2 text-sm">
                {syncErrors.map((e) => (
                  <li key={e.id} className="rounded-lg border border-[var(--color-border)] p-3">
                    <p className="font-medium">{e.reason_code ?? e.phase ?? 'error'}</p>
                    <p className="text-[var(--color-muted)]">{e.error_message}</p>
                  </li>
                ))}
              </ul>
            )}
          </TabPanel>
          <TabPanel>
            <ProductPayloadEditor
              key={`${product.id}-${product.updated_at ?? ''}`}
              product={product}
              ayRoots={ayRootsQuery.data?.items ?? []}
              ayRootsLoading={ayRootsQuery.isLoading || ayRootsQuery.isFetching}
              ayRootsError={ayRootsQuery.isError}
              onSaved={() => void q.refetch()}
              onPreviewStateChange={setPreviewReady}
              onCopy={() => copyJson(product)}
            />
          </TabPanel>
        </TabPanels>
      </TabGroup>
      <ProductAyCategoryMapDialog
        open={ayCategoryDialogOpen}
        onClose={() => setAyCategoryDialogOpen(false)}
        productId={product.id}
        roots={ayRootsQuery.data?.items ?? []}
        rootsLoading={ayRootsQuery.isLoading || ayRootsQuery.isFetching}
        rootsError={ayRootsQuery.isError}
      />
    </div>
  )
}

function ProductPayloadEditor({
  product,
  ayRoots,
  ayRootsLoading,
  ayRootsError,
  onSaved,
  onPreviewStateChange,
  onCopy,
}: {
  product: ProductDetailResponse['product']
  ayRoots: AyCategorySearchItem[]
  ayRootsLoading: boolean
  ayRootsError: boolean
  onSaved: () => void
  onPreviewStateChange: (ready: boolean | null) => void
  onCopy: () => void
}) {
  const { t } = useTranslation('common')
  const { t: tp } = useTranslation('productDetail')
  const [draft, setDraft] = useState({
    export_title: product.export_title ?? '',
    export_description: product.export_description ?? '',
    export_material_composition: product.export_material_composition ?? '',
    ay_category_id: product.ay_category_id ? String(product.ay_category_id) : '',
    ay_category_path: product.ay_category_path ?? '',
    ay_brand_id: product.ay_brand_id ? String(product.ay_brand_id) : '',
    ay_manual_required_attributes_json: product.ay_manual_required_attributes_json ?? '',
  })
  const [preview, setPreview] = useState<{ ready: boolean; payload: unknown; errors: string[] } | null>(null)

  const saveDraftMutation = useMutation({
    mutationFn: () =>
      saveProductDraft(product.id, {
        export_title: draft.export_title || null,
        export_description: draft.export_description || null,
        export_material_composition: draft.export_material_composition || null,
        ay_category_id: draft.ay_category_id ? Number(draft.ay_category_id) : null,
        ay_category_path: draft.ay_category_id ? draft.ay_category_path?.trim() || null : null,
        ay_brand_id: draft.ay_brand_id ? Number(draft.ay_brand_id) : null,
        ay_manual_required_attributes_json: draft.ay_manual_required_attributes_json || null,
      }),
    onSuccess: () => {
      toastSuccess(tp('draftSaved'))
      onSaved()
    },
    onError: (e) => toastError('Save failed', normalizeAxiosError(e)),
  })

  const previewMutation = useMutation({
    mutationFn: () => getProductPayloadPreview(product.id),
    onSuccess: (res) => {
      setPreview({ ready: res.ready, payload: res.payload, errors: res.errors })
      onPreviewStateChange(res.ready)
      if (res.ready) toastSuccess(tp('payloadReady'))
      else toastError(tp('payloadMissing'))
    },
    onError: (e) => toastError('Preview failed', normalizeAxiosError(e)),
  })

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardContent className="space-y-3 p-4">
          <p className="text-sm text-[var(--color-muted)]">{tp('payloadHint')}</p>
          <Input
            value={draft.export_title}
            onChange={(e) => setDraft((d) => ({ ...d, export_title: e.target.value }))}
            placeholder={tp('exportTitle')}
          />
          <textarea
            value={draft.export_description}
            onChange={(e) => setDraft((d) => ({ ...d, export_description: e.target.value }))}
            className="min-h-24 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm"
            placeholder={tp('exportDescription')}
          />
          <Input
            value={draft.export_material_composition}
            onChange={(e) => setDraft((d) => ({ ...d, export_material_composition: e.target.value }))}
            placeholder={tp('materialComposition')}
          />
          <div className="space-y-2">
            <p className="text-xs font-medium text-[var(--color-muted)]">{tp('ayCategoryCascadeLabel')}</p>
            <p className="text-xs text-[var(--color-muted)]">{tp('ayCategoryCascadeHint')}</p>
            {draft.ay_category_path ? (
              <p className="rounded-lg border border-[var(--color-border)] bg-zinc-50 px-2 py-1.5 text-xs dark:bg-zinc-900/50">
                {draft.ay_category_path}
                <span className="ml-2 font-mono text-[var(--color-muted)]">#{draft.ay_category_id}</span>
              </p>
            ) : null}
            <AyCategoryCascadePicker
              key={product.id}
              rowKey={product.id}
              roots={ayRoots}
              rootsLoading={ayRootsLoading}
              rootsError={ayRootsError}
              onSelect={(m) =>
                setDraft((d) => ({
                  ...d,
                  ay_category_id: String(m.id),
                  ay_category_path: m.path,
                }))
              }
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{tp('ayBrandId')}</label>
            <Input
              value={draft.ay_brand_id}
              onChange={(e) => setDraft((d) => ({ ...d, ay_brand_id: e.target.value }))}
              placeholder={tp('ayBrandId')}
            />
          </div>
          <textarea
            value={draft.ay_manual_required_attributes_json}
            onChange={(e) => setDraft((d) => ({ ...d, ay_manual_required_attributes_json: e.target.value }))}
            className="min-h-24 w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 font-mono text-xs"
            placeholder={tp('manualRequiredJson')}
          />
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="secondary"
              onClick={() => saveDraftMutation.mutate()}
              disabled={saveDraftMutation.isPending}
            >
              {tp('saveDraftFields')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              onClick={() => previewMutation.mutate()}
              disabled={previewMutation.isPending}
            >
              {tp('previewAyPayload')}
            </Button>
          </div>
        </CardContent>
      </Card>
      <div className="space-y-3">
        <JsonViewer title={tp('productApi')} value={product} />
        {preview ? (
          <>
            {preview.errors.length > 0 ? (
              <Card>
                <CardContent className="p-4">
                  <p className="mb-2 text-sm font-medium text-red-600">{tp('missingInvalidFields')}</p>
                  <ul className="list-inside list-disc space-y-1 text-xs text-red-600">
                    {preview.errors.map((err, i) => (
                      <li key={`${i}-${err}`}>{err}</li>
                    ))}
                  </ul>
                </CardContent>
              </Card>
            ) : null}
            <JsonViewer title={tp('ayPayloadPreview')} value={preview.payload ?? {}} />
          </>
        ) : (
          <p className="text-sm text-[var(--color-muted)]">{t('refresh')}</p>
        )}
        <div className="mt-2">
          <Button variant="secondary" type="button" onClick={onCopy}>
            {tp('copyProductJson')}
          </Button>
        </div>
      </div>
    </div>
  )
}

function Row({ k, v }: { k: string; v: ReactNode }) {
  return (
    <div className="flex justify-between gap-4 border-b border-[var(--color-border)] py-1 last:border-0">
      <span className="text-[var(--color-muted)]">{k}</span>
      <span className="text-right font-medium">{v}</span>
    </div>
  )
}
