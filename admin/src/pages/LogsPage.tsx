import { format, formatDistanceToNow } from 'date-fns'
import { Helmet } from 'react-helmet-async'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { mockLogs, mockMetrics, mockRuns, type MockSyncLog } from '@/features/logs/mockData'

export default function LogsPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: tl } = useTranslation('logsPage')
  const [q, setQ] = useState('')
  const [level, setLevel] = useState<'all' | MockSyncLog['level']>('all')

  const logs = useMemo(() => {
    return mockLogs.filter((l) => {
      if (level !== 'all' && l.level !== level) return false
      if (!q.trim()) return true
      return l.message.toLowerCase().includes(q.toLowerCase()) || l.runId.includes(q)
    })
  }, [q, level])

  return (
    <div>
      <Helmet>
        <title>
          {tn('logs')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('logs') }]} />
      <PageHeader
        title={tn('logs')}
        description={tl('description')}
      />

      <div className="mb-4 grid gap-4 sm:grid-cols-3">
        {mockMetrics.map((m) => (
          <Card key={m.name}>
            <CardContent className="p-4">
              <p className="text-xs text-[var(--color-muted)]">{m.name}</p>
              <p className="mt-1 text-2xl font-semibold">{m.value}</p>
              <p className="text-xs text-[var(--color-muted)]">{tl('trend')}: {m.trend}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card className="mb-4">
        <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
          <div className="flex-1">
            <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{tl('searchLogs')}</label>
            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={tl('searchPlaceholder')} />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]">{tl('level')}</label>
            <select
              className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm sm:w-40"
              value={level}
              onChange={(e) => setLevel(e.target.value as typeof level)}
            >
              <option value="all">all</option>
              <option value="info">info</option>
              <option value="warn">warn</option>
              <option value="error">error</option>
            </select>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{tl('runTimeline')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {mockRuns.map((r) => (
              <div key={r.id} className="rounded-lg border border-[var(--color-border)] p-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-mono text-xs">{r.id}</span>
                  <Badge tone={r.ok ? 'success' : 'danger'}>{r.ok ? 'ok' : 'failed'}</Badge>
                </div>
                <p className="mt-1 font-medium">{r.command}</p>
                <p className="text-xs text-[var(--color-muted)]">
                  {format(new Date(r.startedAt), 'PPpp')} · {(r.durationMs / 1000).toFixed(1)}s
                </p>
              </div>
            ))}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{tl('expandableLogs')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {logs.length === 0 ? (
              <p className="text-sm text-[var(--color-muted)]">{t('noData')}</p>
            ) : (
              logs.map((l) => (
                <details key={l.id} className="rounded-lg border border-[var(--color-border)] p-3 text-sm">
                  <summary className="cursor-pointer list-none font-medium [&::-webkit-details-marker]:hidden">
                    <span className="flex items-center justify-between gap-2">
                      <span>{l.message}</span>
                      <Badge
                        tone={l.level === 'error' ? 'danger' : l.level === 'warn' ? 'warning' : 'default'}
                        className="shrink-0"
                      >
                        {l.level}
                      </Badge>
                    </span>
                  </summary>
                  <p className="mt-2 text-xs text-[var(--color-muted)]">
                    {tl('run')} {l.runId} · {formatDistanceToNow(new Date(l.at), { addSuffix: true })}
                  </p>
                </details>
              ))
            )}
          </CardContent>
        </Card>
      </div>

      <div className="mt-6 flex justify-end">
        <Button variant="secondary" type="button" onClick={() => window.alert(tl('exportPending'))}>
          {tl('exportRunBundle')}
        </Button>
      </div>
    </div>
  )
}
