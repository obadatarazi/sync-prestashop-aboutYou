import {
  Combobox,
  ComboboxInput,
  ComboboxOption,
  ComboboxOptions,
  Dialog,
  DialogBackdrop,
  DialogPanel,
} from '@headlessui/react'
import { MagnifyingGlassIcon } from '@heroicons/react/24/outline'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { useUiStore } from '@/store/uiStore'
import { useSyncRunMutation } from '@/hooks/useSyncMutation'
import { toastError, toastSuccess } from '@/store/toastStore'
import { normalizeAxiosError } from '@/api/errors'

type Entry = { id: string; label: string; to?: string; action?: 'status' }

export function CommandPalette() {
  const { t } = useTranslation(['nav', 'common'])
  const open = useUiStore((s) => s.commandPaletteOpen)
  const setOpen = useUiStore((s) => s.setCommandPaletteOpen)
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const syncRun = useSyncRunMutation()

  const entries = useMemo<Entry[]>(
    () => [
      { id: 'dash', label: t('dashboard'), to: '/' },
      { id: 'prod', label: t('products'), to: '/products' },
      { id: 'ord', label: t('orders'), to: '/orders' },
      { id: 'sync', label: t('sync'), to: '/sync' },
      { id: 'set', label: t('settings'), to: '/settings' },
      { id: 'logs', label: t('logs'), to: '/logs' },
      { id: 'retry', label: t('retry'), to: '/retry' },
      { id: 'map', label: t('mappings'), to: '/mappings' },
      { id: 'img', label: t('images'), to: '/images' },
      { id: 'status', label: t('status', { ns: 'common' }), action: 'status' },
    ],
    [t],
  )

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase()
    if (!s) return entries
    return entries.filter((a) => a.label.toLowerCase().includes(s))
  }, [entries, q])

  return (
    <Dialog open={open} onClose={() => setOpen(false)} className="relative z-[90]">
      <DialogBackdrop className="fixed inset-0 bg-black/50 backdrop-blur-sm" />
      <div className="fixed inset-0 flex items-start justify-center p-4 pt-[15vh]">
        <DialogPanel className="w-full max-w-lg overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] shadow-2xl">
          <Combobox
            value={null as string | null}
            onChange={(id: string | null) => {
              if (!id) return
              const e = entries.find((x) => x.id === id)
              if (!e) return
              if (e.action === 'status') {
                syncRun.mutate(
                  { command: 'status' },
                  {
                    onSuccess: (res) => {
                      toastSuccess(t('status', { ns: 'common' }), res.message ?? 'ok')
                      setOpen(false)
                    },
                    onError: (err) => toastError('Status failed', normalizeAxiosError(err)),
                  },
                )
              } else if (e.to) {
                navigate(e.to)
                setOpen(false)
              }
              setQ('')
            }}
          >
            <div className="flex items-center gap-2 border-b border-[var(--color-border)] px-3">
              <MagnifyingGlassIcon className="h-5 w-5 text-[var(--color-muted)]" />
              <ComboboxInput
                autoFocus
                placeholder={t('search', { ns: 'common' })}
                className="w-full border-0 bg-transparent py-3 text-sm outline-none placeholder:text-[var(--color-muted)]"
                onChange={(ev) => setQ(ev.target.value)}
                onKeyDown={(ev) => {
                  if (ev.key === 'Escape') setOpen(false)
                }}
                displayValue={() => q}
              />
            </div>
            <ComboboxOptions static className="max-h-72 overflow-auto p-2">
              {filtered.length === 0 ? (
                <p className="px-2 py-6 text-center text-sm text-[var(--color-muted)]">{t('noData', { ns: 'common' })}</p>
              ) : (
                filtered.map((a) => (
                  <ComboboxOption
                    key={a.id}
                    value={a.id}
                    className="cursor-pointer rounded-lg px-3 py-2 text-sm data-[focus]:bg-zinc-100 dark:data-[focus]:bg-zinc-800"
                  >
                    {a.label}
                  </ComboboxOption>
                ))
              )}
            </ComboboxOptions>
          </Combobox>
        </DialogPanel>
      </div>
    </Dialog>
  )
}
