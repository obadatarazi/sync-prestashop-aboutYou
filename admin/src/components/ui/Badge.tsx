import { cn } from '@/lib/cn'
import type { HTMLAttributes } from 'react'

const tones: Record<string, string> = {
  default: 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200',
  success: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
  warning: 'bg-amber-100 text-amber-950 dark:bg-amber-900/40 dark:text-amber-100',
  danger: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
  info: 'bg-sky-100 text-sky-950 dark:bg-sky-900/40 dark:text-sky-100',
}

type Props = HTMLAttributes<HTMLSpanElement> & { tone?: keyof typeof tones }

export function Badge({ className, tone = 'default', ...props }: Props) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
        tones[tone] ?? tones.default,
        className,
      )}
      {...props}
    />
  )
}
