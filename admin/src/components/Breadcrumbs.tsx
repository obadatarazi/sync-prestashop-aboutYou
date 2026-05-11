import { ChevronRightIcon, HomeIcon } from '@heroicons/react/20/solid'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { cn } from '@/lib/cn'

export type Crumb = { label: string; to?: string }

export function Breadcrumbs({ items }: { items: Crumb[] }) {
  const { t } = useTranslation('common')
  return (
    <nav aria-label="Breadcrumb" className="mb-4 flex items-center gap-1 text-sm text-[var(--color-muted)]">
      <Link to="/" className="inline-flex items-center hover:text-zinc-900 dark:hover:text-zinc-100">
        <HomeIcon className="h-4 w-4" aria-hidden />
        <span className="sr-only">{t('appName')}</span>
      </Link>
      {items.map((c, i) => (
        <span key={`${c.label}-${i}`} className="flex items-center gap-1">
          <ChevronRightIcon className="h-4 w-4 shrink-0 opacity-60" aria-hidden />
          {c.to && i < items.length - 1 ? (
            <Link to={c.to} className="hover:text-zinc-900 dark:hover:text-zinc-100">
              {c.label}
            </Link>
          ) : (
            <span className={cn(i === items.length - 1 && 'font-medium text-zinc-800 dark:text-zinc-100')}>
              {c.label}
            </span>
          )}
        </span>
      ))}
    </nav>
  )
}
