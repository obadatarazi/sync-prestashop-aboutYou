import { cn } from '@/lib/cn'
import type { ButtonHTMLAttributes } from 'react'

const variants = {
  primary:
    'bg-gradient-to-r from-indigo-600 to-violet-600 text-white shadow-sm hover:from-indigo-500 hover:to-violet-500 dark:from-indigo-500 dark:to-violet-500 dark:hover:from-indigo-400 dark:hover:to-violet-400',
  secondary:
    'bg-[var(--color-surface-elevated)] border border-[var(--color-border)] text-zinc-900 shadow-sm hover:bg-zinc-50 dark:text-zinc-100 dark:hover:bg-zinc-800/80',
  ghost: 'text-zinc-700 hover:bg-zinc-100/90 dark:text-zinc-300 dark:hover:bg-zinc-800',
  danger: 'bg-red-600 text-white hover:bg-red-500',
  link: 'text-[var(--color-accent)] underline-offset-4 hover:underline',
} as const

type Variant = keyof typeof variants

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
}

export function Button({ className, variant = 'primary', type = 'button', ...props }: Props) {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150 disabled:pointer-events-none disabled:opacity-50',
        variants[variant],
        className,
      )}
      {...props}
    />
  )
}
