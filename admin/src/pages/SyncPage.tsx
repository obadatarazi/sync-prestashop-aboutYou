import { zodResolver } from '@hookform/resolvers/zod'
import { Helmet } from 'react-helmet-async'
import { useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { JsonViewer } from '@/components/JsonViewer'
import { PageHeader } from '@/components/PageHeader'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { useSyncController } from '@/features/sync/hooks/useSyncController'
import { syncFormSchema, type SyncFormInput } from '@/schemas/syncForm'

export default function SyncPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('syncPage')

  const form = useForm<SyncFormInput>({
    resolver: zodResolver(syncFormSchema),
    defaultValues: {
      command: 'status',
      since: '',
      ps_product_ids_text: '',
    },
  })

  const c = useSyncController(form)

  const cmd = useWatch({ control: form.control, name: 'command' })

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
      />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{t('command')}</CardTitle>
          </CardHeader>
          <CardContent>
            <form className="space-y-4" onSubmit={form.handleSubmit(c.onSubmit)} noValidate>
              <div>
                <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{t('command')}</label>
                <select
                  className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm"
                  {...form.register('command')}
                >
                  {(
                    [
                      'status',
                      'products',
                      'products:inc',
                      'stock',
                      'orders',
                      'order-status',
                      'retry',
                      'all',
                    ] as const
                  ).map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{tn('sinceLabel')}</label>
                <Input placeholder={tn('sincePlaceholder')} {...form.register('since')} />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">
                  {tn('productIdsLabel')}
                </label>
                <textarea
                  className="min-h-[88px] w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 font-mono text-xs outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30"
                  placeholder={tn('productIdsPlaceholder')}
                  {...form.register('ps_product_ids_text')}
                />
              </div>
              <p className="text-xs text-[var(--color-muted)]">
                {tn('flagsHint')}
              </p>
              <Button type="submit" className="w-full" disabled={c.syncRun.isPending}>
                {c.syncRun.isPending ? t('running') : t('run')}
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{t('presets')}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {(['products', 'products:inc', 'stock', 'orders', 'order-status', 'retry', 'all', 'status'] as const).map((preset) => (
              <Button
                key={preset}
                variant="secondary"
                type="button"
                disabled={c.syncRun.isPending}
                onClick={() => c.presetRun(preset)}
              >
                {preset}
              </Button>
            ))}
          </CardContent>
        </Card>
      </div>

      {c.lastResult ? (
        <div className="mt-6">
          <JsonViewer title={tn('lastApiResponse')} value={c.lastResult} />
        </div>
      ) : null}

      <div className="mt-6 rounded-lg border border-dashed border-[var(--color-border)] bg-zinc-950/5 p-4 font-mono text-xs text-zinc-700 dark:text-zinc-300">
        <p className="mb-2 font-sans text-sm font-medium text-zinc-900 dark:text-zinc-100">{tn('operatorNotesTitle')}</p>
        <ul className="list-inside list-disc space-y-1">
          <li>{tn('operatorNotesLine1')}</li>
          <li>{tn('operatorNotesLine2', { command: cmd })}</li>
        </ul>
      </div>
    </div>
  )
}
