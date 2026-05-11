import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import { HelpCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/cn'

type Props = {
  className?: string
}

/**
 * Help control: same plain-language explanation for every setting (stored under `hints.fallback`).
 */
export function SettingFieldHint({ className }: Props) {
  const { t } = useTranslation('settingsPage')
  const text = t('hints.fallback')

  return (
    <Popover className={cn('relative inline-flex align-middle', className)}>
      <PopoverButton
        type="button"
        className={cn(
          'inline-flex shrink-0 rounded-md p-0.5 text-zinc-400 transition-colors hover:bg-zinc-200/90 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200',
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface-elevated)] dark:focus-visible:ring-offset-zinc-900',
        )}
        aria-label={t('hints.hintButtonAria')}
      >
        <HelpCircle className="h-4 w-4" strokeWidth={2} aria-hidden />
      </PopoverButton>
      <PopoverPanel
        portal
        modal={false}
        anchor={{ to: 'bottom start', gap: '0.5rem' }}
        className="z-[200]"
      >
        <div className="max-w-sm rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-3 text-sm leading-relaxed text-zinc-800 shadow-lg dark:text-zinc-100">
          <p className="m-0">{text}</p>
        </div>
      </PopoverPanel>
    </Popover>
  )
}
