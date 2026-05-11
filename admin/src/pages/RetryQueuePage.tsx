import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { mockRetryJobs } from '@/features/retry/mockData'

export default function RetryQueuePage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: tr } = useTranslation('retryPage')

  return (
    <div>
      <Helmet>
        <title>
          {tn('retry')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('retry') }]} />
      <PageHeader
        title={tn('retry')}
        description={tr('description')}
      />

      <Card className="mb-6">
        <CardHeader>
          <CardTitle>{tr('deadQueue')}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="text-left text-xs uppercase text-[var(--color-muted)]">
                <tr>
                  <th className="py-2 pr-4">{tr('job')}</th>
                  <th className="py-2 pr-4">{tr('entity')}</th>
                  <th className="py-2 pr-4">{tr('attempts')}</th>
                  <th className="py-2">{tr('payload')}</th>
                </tr>
              </thead>
              <tbody>
                {mockRetryJobs
                  .filter((j) => j.status === 'dead')
                  .map((j) => (
                    <tr key={j.id} className="border-t border-[var(--color-border)]">
                      <td className="py-2 pr-4">
                        <Badge tone="danger">{j.type}</Badge>
                      </td>
                      <td className="py-2 pr-4 font-mono text-xs">{j.entityKey}</td>
                      <td className="py-2 pr-4">{j.attempts}</td>
                      <td className="max-w-xs truncate py-2 font-mono text-xs">{j.payloadPreview}</td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{tr('pending')}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="text-left text-xs uppercase text-[var(--color-muted)]">
                <tr>
                  <th className="py-2 pr-4">{tr('job')}</th>
                  <th className="py-2 pr-4">{tr('entity')}</th>
                  <th className="py-2 pr-4">{tr('attempts')}</th>
                  <th className="py-2 pr-4">{tr('lastError')}</th>
                  <th className="py-2 text-right">{tr('action')}</th>
                </tr>
              </thead>
              <tbody>
                {mockRetryJobs
                  .filter((j) => j.status !== 'dead')
                  .map((j) => (
                    <tr key={j.id} className="border-t border-[var(--color-border)]">
                      <td className="py-2 pr-4">
                        <Badge tone="warning">{j.type}</Badge>
                      </td>
                      <td className="py-2 pr-4 font-mono text-xs">{j.entityKey}</td>
                      <td className="py-2 pr-4">{j.attempts}</td>
                      <td className="py-2 pr-4 text-xs text-red-600">{j.lastError}</td>
                      <td className="py-2 text-right">
                        <Button variant="secondary" type="button" disabled onClick={() => undefined}>
                          {tr('retry')}
                        </Button>
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
