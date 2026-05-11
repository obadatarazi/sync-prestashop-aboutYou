import { cn } from '@/lib/cn'
import type { ReactNode } from 'react'

type Props = {
  title: string
  description?: string
  actions?: ReactNode
  className?: string
}

export function PageHeader({ title, description, actions, className }: Props) {
  return (
    <header
      className={cn(
        'sticky top-16 z-10 -mx-4 mb-6 border-b border-[var(--color-border)] bg-[color-mix(in_oklab,var(--color-surface)_88%,white_12%)]/95 px-4 py-5 backdrop-blur-md dark:bg-[color-mix(in_oklab,var(--color-surface)_94%,black_6%)]/95 sm:-mx-6 sm:px-6',
        className,
      )}
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{title}</h1>
          {description ? (
            <p className="mt-1 max-w-3xl text-sm leading-relaxed text-[var(--color-muted)]">{description}</p>
          ) : null}
        </div>
        {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
      </div>
    </header>
  )
}
