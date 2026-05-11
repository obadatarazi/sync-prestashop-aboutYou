import { cn } from '@/lib/cn'
import type { InputHTMLAttributes } from 'react'

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return (
    <input
      className={cn(
        'w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm outline-none transition-shadow placeholder:text-[var(--color-muted)] focus:ring-2 focus:ring-[var(--color-accent)]/30',
        className,
      )}
      {...props}
    />
  )
}
