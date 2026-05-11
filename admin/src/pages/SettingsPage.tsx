import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/react'
import { useMemo, useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import {
  AlertTriangle,
  ChevronDown,
  ImageIcon,
  Loader2,
  Package,
  RefreshCw,
  Settings2,
  ShieldAlert,
  Sparkles,
  Wand2,
} from 'lucide-react'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { SettingFieldHint } from '@/components/SettingFieldHint'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Switch } from '@/components/ui/Switch'
import { useSaveSettingsMutation, useSettingsQuery } from '@/hooks/useSettingsQuery'
import { normalizeAxiosError } from '@/api/errors'
import { toastError, toastSuccess } from '@/store/toastStore'
import { cn } from '@/lib/cn'
import type { LucideIcon } from 'lucide-react'
import type { SettingRow } from '@/types/api'

function toStoredString(type: string, raw: string): string {
  if (type === 'boolean') return raw === 'true' || raw === '1' ? 'true' : 'false'
  if (type === 'integer') return String(Number.parseInt(raw, 10) || 0)
  return raw
}

function rowsToMap(rows: SettingRow[]): Record<string, string> {
  const m: Record<string, string> = {}
  for (const r of rows) {
    m[r.key] = r.value ?? ''
  }
  return m
}

function formatGroupTitle(group: string): string {
  return group
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ')
}

const GROUP_ICONS: Record<string, LucideIcon> = {
  safety: ShieldAlert,
  prestashop: Package,
  aboutyou: Sparkles,
  sync: RefreshCw,
  images: ImageIcon,
  features: Wand2,
}

const GROUP_ACCENT: Record<string, string> = {
  safety: 'from-rose-500/15 to-amber-600/10 text-rose-700 dark:text-rose-300',
  prestashop: 'from-sky-500/15 to-blue-600/10 text-sky-600 dark:text-sky-400',
  aboutyou: 'from-violet-500/15 to-fuchsia-600/10 text-violet-600 dark:text-violet-400',
  sync: 'from-emerald-500/15 to-teal-600/10 text-emerald-600 dark:text-emerald-400',
  images: 'from-amber-500/15 to-orange-600/10 text-amber-600 dark:text-amber-400',
  features: 'from-indigo-500/15 to-purple-600/10 text-indigo-600 dark:text-indigo-400',
}

function groupIcon(group: string): LucideIcon {
  const key = group.toLowerCase()
  return GROUP_ICONS[key] ?? Settings2
}

function groupAccent(group: string): string {
  const key = group.toLowerCase()
  return GROUP_ACCENT[key] ?? 'from-zinc-500/12 to-zinc-600/8 text-zinc-600 dark:text-zinc-400'
}

function SettingsForm({ rows }: { rows: SettingRow[] }) {
  const { t: ts } = useTranslation('settingsPage')
  const [draft, setDraft] = useState(() => rowsToMap(rows))
  const [baseline, setBaseline] = useState(() => rowsToMap(rows))
  const save = useSaveSettingsMutation()

  const grouped = useMemo(() => {
    const g = new Map<string, SettingRow[]>()
    for (const r of rows) {
      const gn = r.group_name || 'other'
      if (!g.has(gn)) g.set(gn, [])
      g.get(gn)!.push(r)
    }
    const groupOrder = (name: string) => (name.toLowerCase() === 'safety' ? 0 : 1)
    return [...g.entries()].sort(([a], [b]) => {
      const oa = groupOrder(a)
      const ob = groupOrder(b)
      if (oa !== ob) return oa - ob
      return a.localeCompare(b)
    })
  }, [rows])

  const dirtyKeys = useMemo(() => Object.keys(draft).filter((k) => draft[k] !== baseline[k]), [draft, baseline])

  const setField = (key: string, value: string) => {
    setDraft((d) => ({ ...d, [key]: value }))
  }

  const saveGroup = (groupRows: SettingRow[]) => {
    const payload: Record<string, string> = {}
    for (const r of groupRows) {
      if (draft[r.key] !== baseline[r.key]) {
        payload[r.key] = toStoredString(r.type, draft[r.key] ?? '')
      }
    }
    if (!Object.keys(payload).length) {
      toastSuccess(ts('nothingToSave'))
      return
    }
    save.mutate(payload, {
      onSuccess: (res) => {
        toastSuccess(ts('saved'), res.saved?.join(', ') ?? '')
        setBaseline((b) => ({ ...b, ...Object.fromEntries(Object.keys(payload).map((k) => [k, draft[k]])) }))
      },
      onError: (e) => toastError(ts('saveFailed'), normalizeAxiosError(e)),
    })
  }

  return (
    <div className="space-y-8">
      {grouped.map(([group, groupRows]) => {
        const Icon = groupIcon(group)
        const accent = groupAccent(group)
        const dirtyInGroup = groupRows.filter((r) => draft[r.key] !== baseline[r.key]).length
        const hasDirty = dirtyInGroup > 0

        return (
          <Card key={group} className="overflow-hidden shadow-md ring-1 ring-[var(--color-border)]/60">
            <Disclosure defaultOpen>
              {({ open }) => (
                <>
                  <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b border-[var(--color-border)]/80 bg-[color-mix(in_oklab,var(--color-surface-elevated)_96%,var(--color-primary)_4%)] px-5 py-4">
                    <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                      <DisclosureButton
                        type="button"
                        className="group flex min-w-0 flex-1 items-center gap-2 rounded-xl py-1 ps-1 pe-2 text-left outline-none transition hover:bg-zinc-100/50 focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface-elevated)] dark:hover:bg-zinc-800/40 dark:focus-visible:ring-offset-zinc-900 sm:gap-3 sm:ps-2"
                      >
                        <ChevronDown
                          className={cn(
                            'h-5 w-5 shrink-0 text-zinc-500 transition-transform duration-200 dark:text-zinc-400',
                            open && 'rotate-180',
                          )}
                          aria-hidden
                        />
                        <div
                          className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br ${accent} border border-[var(--color-border)]/50 shadow-sm`}
                        >
                          <Icon className="h-5 w-5" strokeWidth={1.75} aria-hidden />
                        </div>
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="text-base font-semibold tracking-tight">
                              {formatGroupTitle(group)}
                            </CardTitle>
                            {hasDirty && !open ? (
                              <span
                                className="inline-flex h-2 w-2 shrink-0 rounded-full bg-amber-500 shadow-sm ring-2 ring-amber-200/80 dark:ring-amber-900/50"
                                title={ts('unsavedCollapsedTitle')}
                                aria-label={ts('unsavedCollapsedTitle')}
                              />
                            ) : null}
                          </div>
                          <p className="mt-0.5 text-xs text-[var(--color-muted)]">
                            {groupRows.length} {groupRows.length === 1 ? ts('fieldSingular') : ts('fieldPlural')}
                            {hasDirty ? (
                              <span className="ms-2 font-medium text-amber-700 dark:text-amber-300">
                                · {dirtyInGroup} {ts('unsavedInGroup')}
                              </span>
                            ) : null}
                          </p>
                        </div>
                      </DisclosureButton>
                      <SettingFieldHint className="shrink-0 self-center" />
                    </div>
                    <Button
                      variant="secondary"
                      type="button"
                      disabled={save.isPending || !hasDirty}
                      onClick={() => saveGroup(groupRows)}
                      className="shrink-0 border-indigo-200/80 bg-white/90 hover:bg-indigo-50/90 dark:border-indigo-500/25 dark:bg-zinc-800/90 dark:hover:bg-indigo-950/50"
                    >
                      {save.isPending ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
                      {ts('saveGroup')}
                    </Button>
                  </CardHeader>
                  <DisclosurePanel unmount={false}>
                    <CardContent className="divide-y divide-[var(--color-border)]/80 p-0">
                      {groupRows.map((r) => {
                        const isDirty = dirtyKeys.includes(r.key)
                        const labelText = r.label ?? r.key

                        if (r.type === 'boolean') {
                          const on = draft[r.key] === 'true'
                          return (
                            <div
                              key={r.key}
                              className="flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-900/25"
                            >
                              <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                  <p
                                    className="text-sm font-medium text-zinc-900 dark:text-zinc-100"
                                    id={`${r.key}-label`}
                                  >
                                    {labelText}
                                  </p>
                                  <SettingFieldHint className="-mt-px" />
                                  {isDirty ? (
                                    <span className="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">
                                      {ts('modified')}
                                    </span>
                                  ) : null}
                                </div>
                                <p className="mt-1 font-mono text-[11px] leading-relaxed text-[var(--color-muted)]">
                                  {r.key}
                                </p>
                                <p className="mt-1.5 text-xs text-[var(--color-muted)]">
                                  {on ? (
                                    <span className="text-emerald-600 dark:text-emerald-400">{ts('stateOn')}</span>
                                  ) : null}
                                  {!on ? (
                                    <span className="text-zinc-500 dark:text-zinc-500">{ts('stateOff')}</span>
                                  ) : null}
                                </p>
                              </div>
                              <Switch
                                checked={on}
                                onCheckedChange={(next) => setField(r.key, next ? 'true' : 'false')}
                                disabled={save.isPending}
                                aria-labelledby={`${r.key}-label`}
                              />
                            </div>
                          )
                        }

                        return (
                          <div
                            key={r.key}
                            className="px-5 py-4 transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-900/25"
                          >
                            <div className="mb-2 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                              <label className="text-sm font-medium text-zinc-900 dark:text-zinc-100" htmlFor={r.key}>
                                {labelText}
                              </label>
                              <SettingFieldHint className="-mt-px" />
                              {r.type === 'password' ? (
                                <span className="rounded-md bg-zinc-200/90 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                                  {ts('secret')}
                                </span>
                              ) : null}
                              {isDirty ? (
                                <span className="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">
                                  {ts('modified')}
                                </span>
                              ) : null}
                            </div>
                            <Input
                              id={r.key}
                              type={r.type === 'password' ? 'password' : r.type === 'integer' ? 'number' : 'text'}
                              inputMode={r.type === 'integer' ? 'numeric' : undefined}
                              value={draft[r.key] ?? ''}
                              onChange={(e) => setField(r.key, e.target.value)}
                              autoComplete="off"
                              className="font-mono text-sm"
                            />
                            <p className="mt-1.5 font-mono text-[11px] text-[var(--color-muted)]">{r.key}</p>
                          </div>
                        )
                      })}
                    </CardContent>
                  </DisclosurePanel>
                </>
              )}
            </Disclosure>
          </Card>
        )
      })}
    </div>
  )
}

export default function SettingsPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: ts } = useTranslation('settingsPage')
  const q = useSettingsQuery()
  const rows = q.data?.rows ?? []

  return (
    <div>
      <Helmet>
        <title>
          {tn('settings')} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('settings') }]} />
      <PageHeader
        title={tn('settings')}
        description={ts('description')}
        actions={
          <Button variant="secondary" type="button" onClick={() => q.refetch()} disabled={q.isFetching}>
            {q.isFetching ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden /> : null}
            {t('refresh')}
          </Button>
        }
      />

      <div className="mb-8 flex gap-3 rounded-2xl border border-amber-200/90 bg-gradient-to-br from-amber-50 via-orange-50/40 to-amber-50/80 p-4 text-sm text-amber-950 shadow-sm dark:border-amber-900/40 dark:from-amber-950/35 dark:via-orange-950/20 dark:to-amber-950/25 dark:text-amber-50">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-200/60 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200">
          <AlertTriangle className="h-5 w-5" strokeWidth={1.75} aria-hidden />
        </div>
        <p className="min-w-0 flex-1 leading-relaxed">{ts('flagsNotice')}</p>
      </div>

      {q.isLoading ? (
        <div className="space-y-8">
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className="h-48 animate-pulse rounded-2xl bg-gradient-to-br from-zinc-200/90 to-zinc-100/80 dark:from-zinc-800 dark:to-zinc-900/80"
            />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <p className="text-sm text-[var(--color-muted)]">{t('noData')}</p>
      ) : (
        <SettingsForm key={q.dataUpdatedAt} rows={rows} />
      )}
    </div>
  )
}
