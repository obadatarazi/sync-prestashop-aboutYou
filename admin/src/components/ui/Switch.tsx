import { forwardRef, type ButtonHTMLAttributes } from 'react'
import { cn } from '@/lib/cn'

export type SwitchProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'onChange' | 'role'> & {
  checked: boolean
  onCheckedChange: (next: boolean) => void
}

/**
 * Accessible toggle (role="switch"). Thumb position uses logical `start` for RTL.
 */
export const Switch = forwardRef<HTMLButtonElement, SwitchProps>(function Switch(
  { className, checked, onCheckedChange, disabled, type: _t, ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => {
        if (!disabled) onCheckedChange(!checked)
      }}
      className={cn(
        'relative inline-flex h-7 w-[3.25rem] shrink-0 cursor-pointer overflow-hidden rounded-full border border-black/5 shadow-inner transition-colors duration-200 ease-out focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-45 dark:border-white/10',
        checked
          ? 'bg-gradient-to-r from-indigo-600 to-violet-600 dark:from-indigo-500 dark:to-violet-500'
          : 'bg-zinc-200 dark:bg-zinc-600',
        className,
      )}
      {...props}
    >
      <span
        aria-hidden
        className={cn(
          'pointer-events-none absolute top-0.5 h-6 w-6 rounded-full bg-white shadow-md ring-1 ring-black/8 transition-[inset-inline-start] duration-200 ease-out dark:bg-zinc-50 dark:ring-white/12',
          checked ? 'start-[calc(100%-1.625rem)]' : 'start-0.5',
        )}
      />
    </button>
  )
})
