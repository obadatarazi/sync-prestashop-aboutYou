import { Transition } from '@headlessui/react'
import { XMarkIcon } from '@heroicons/react/20/solid'
import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { useToastStore } from '@/store/toastStore'
import { cn } from '@/lib/cn'

export function AppToasts() {
  const { t } = useTranslation('ui')
  const { toasts, dismiss } = useToastStore()
  return (
    <div
      className="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2 p-0 sm:p-0"
      aria-live="polite"
    >
      {toasts.map((toast) => (
        <Transition
          key={toast.id}
          show
          as={Fragment}
          enter="transform transition duration-200 ease-out"
          enterFrom="translate-y-2 opacity-0"
          enterTo="translate-y-0 opacity-100"
          leave="transition duration-150 ease-in"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div
            className={cn(
              'pointer-events-auto flex gap-3 rounded-lg border p-3 shadow-lg',
              toast.kind === 'success' && 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/50',
              toast.kind === 'error' && 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/50',
              toast.kind === 'info' && 'border-[var(--color-border)] bg-[var(--color-surface-elevated)]',
            )}
          >
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-zinc-900 dark:text-zinc-50">{toast.title}</p>
              {toast.description ? (
                <p className="mt-0.5 text-xs text-[var(--color-muted)]">{toast.description}</p>
              ) : null}
            </div>
            <button
              type="button"
              className="shrink-0 rounded p-1 text-zinc-500 hover:bg-black/5 dark:hover:bg-white/10"
              onClick={() => dismiss(toast.id)}
              aria-label={t('dismiss')}
            >
              <XMarkIcon className="h-4 w-4" />
            </button>
          </div>
        </Transition>
      ))}
    </div>
  )
}
