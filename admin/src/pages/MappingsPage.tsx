import { useMemo, useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { AyCategoryCascadePicker } from '@/components/AyCategoryCascadePicker'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import {
  useAyCategoryRootsQuery,
  useMappingCategoriesQuery,
  useMappingsOverviewQuery,
  useSaveMappingCategoriesMutation,
} from '@/hooks/useMappingsQuery'
import { normalizeAxiosError } from '@/api/errors'
import { toastError, toastSuccess } from '@/store/toastStore'
export default function MappingsPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: tm } = useTranslation('mappingsPage')
  const overview = useMappingsOverviewQuery()
  const categoriesQuery = useMappingCategoriesQuery()
  const ayRootsQuery = useAyCategoryRootsQuery()
  const saveMutation = useSaveMappingCategoriesMutation()
  const [searchTerm, setSearchTerm] = useState('')
  const [draft, setDraft] = useState<Record<string, { id: number; path: string }>>({})
  const rows = useMemo(() => categoriesQuery.data?.rows ?? [], [categoriesQuery.data?.rows])

  const filteredRows = useMemo(() => {
    const q = searchTerm.trim().toLowerCase()
    if (!q) return rows
    return rows.filter(
      (row) =>
        row.ps_category_name.toLowerCase().includes(q) ||
        String(row.ps_category_id).includes(q) ||
        String(row.ay_category_id ?? '').includes(q),
    )
  }, [rows, searchTerm])

  const missingCount = useMemo(
    () => rows.filter((row) => (draft[String(row.ps_category_id)]?.id ?? row.ay_category_id ?? 0) <= 0).length,
    [rows, draft],
  )

  const saveAll = () => {
    saveMutation.mutate(draft, {
      onSuccess: (res) => {
        toastSuccess('Mappings saved', String(res.saved))
        setDraft({})
      },
      onError: (e) => toastError('Mapping save failed', normalizeAxiosError(e)),
    })
  }

  return (
    <div>
      <Helmet>
        <title>
          {tn('mappings')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('mappings') }]} />
      <PageHeader
        title={tn('mappings')}
        description={tm('description')}
        actions={
          <div className="flex gap-2">
            <Button
              variant="secondary"
              type="button"
              onClick={() => {
                void categoriesQuery.refetch()
                void ayRootsQuery.refetch()
              }}
              disabled={categoriesQuery.isFetching || ayRootsQuery.isFetching}
            >
              {t('refresh')}
            </Button>
            <Button type="button" onClick={saveAll} disabled={saveMutation.isPending || Object.keys(draft).length === 0}>
              {tm('saveMappings')}
            </Button>
          </div>
        }
      />

      <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-[var(--color-muted)]">{tm('totalCategories')}</p>
            <p className="mt-1 text-2xl font-semibold">{rows.length}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-[var(--color-muted)]">{tm('missingMappings')}</p>
            <p className="mt-1 text-2xl font-semibold">{missingCount}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-[var(--color-muted)]">{tm('attributeMapsCount')}</p>
            <p className="mt-1 text-2xl font-semibold">{overview.data?.attribute_maps_count ?? '—'}</p>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="p-4">
            <p className="text-xs text-[var(--color-muted)]">{tm('materialMapsCount')}</p>
            <p className="mt-1 text-2xl font-semibold">
              {(overview.data?.material_component_maps_count ?? 0) + (overview.data?.material_cluster_maps_count ?? 0)}
            </p>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{tm('categoryMappingTable')}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-3 max-w-sm">
            <Input value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} placeholder={tm('filterPlaceholder')} />
          </div>
          <div className="overflow-x-auto rounded-xl border border-[var(--color-border)]">
            <table className="min-w-full text-sm">
              <thead className="bg-zinc-50 text-left text-xs uppercase text-[var(--color-muted)] dark:bg-zinc-900/50">
                <tr>
                  <th className="px-3 py-2">{tm('psCategory')}</th>
                  <th className="px-3 py-2">{tm('products')}</th>
                  <th className="px-3 py-2">{tm('currentAyMapping')}</th>
                  <th className="px-3 py-2">{tm('mapAyCategory')}</th>
                </tr>
              </thead>
              <tbody>
                {categoriesQuery.isLoading ? (
                  <tr>
                    <td colSpan={4} className="px-3 py-8 text-center text-[var(--color-muted)]">
                      {t('loading')}
                    </td>
                  </tr>
                ) : filteredRows.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="px-3 py-8 text-center text-[var(--color-muted)]">
                      {t('noData')}
                    </td>
                  </tr>
                ) : (
                  filteredRows.map((row) => {
                    const local = draft[String(row.ps_category_id)]
                    const currentId = local?.id ?? row.ay_category_id
                    const currentPath = local?.path ?? row.ay_category_path
                    return (
                      <tr key={row.ps_category_id} className="border-t border-[var(--color-border)] align-top">
                        <td className="px-3 py-2">
                          <p className="font-medium">{row.ps_category_name}</p>
                          <p className="font-mono text-xs text-[var(--color-muted)]">PS #{row.ps_category_id}</p>
                        </td>
                        <td className="px-3 py-2">{row.product_count}</td>
                        <td className="px-3 py-2">
                          {currentId ? (
                            <>
                              <p>{currentPath || `AY #${currentId}`}</p>
                              <p className="font-mono text-xs text-[var(--color-muted)]">AY #{currentId}</p>
                            </>
                          ) : (
                            <p className="text-red-600">{tm('notMapped')}</p>
                          )}
                        </td>
                        <td className="px-3 py-2">
                          <AyCategoryCascadePicker
                            key={row.ps_category_id}
                            rowKey={row.ps_category_id}
                            roots={ayRootsQuery.data?.items ?? []}
                            rootsLoading={ayRootsQuery.isLoading || ayRootsQuery.isFetching}
                            rootsError={ayRootsQuery.isError}
                            onSelect={(mapping) =>
                              setDraft((prev) => ({
                                ...prev,
                                [String(row.ps_category_id)]: mapping,
                              }))
                            }
                          />
                        </td>
                      </tr>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
