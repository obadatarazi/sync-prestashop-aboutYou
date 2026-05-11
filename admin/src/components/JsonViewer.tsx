import { Disclosure, DisclosureButton, DisclosurePanel } from '@headlessui/react'
import { ChevronDownIcon } from '@heroicons/react/24/outline'
import { cn } from '@/lib/cn'

export function JsonViewer({ value, title = 'JSON' }: { value: unknown; title?: string }) {
  const text = JSON.stringify(value, null, 2)
  return (
    <Disclosure defaultOpen>
      {({ open }) => (
        <div className="overflow-hidden rounded-lg border border-[var(--color-border)] bg-zinc-950/5 dark:bg-zinc-950/40">
          <DisclosureButton className="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-medium text-zinc-700 dark:text-zinc-200">
            {title}
            <ChevronDownIcon className={cn('h-4 w-4 transition', open && 'rotate-180')} />
          </DisclosureButton>
          <DisclosurePanel>
            <pre className="max-h-[min(70vh,480px)] overflow-auto border-t border-[var(--color-border)] p-3 text-xs leading-relaxed text-zinc-800 dark:text-zinc-100">
              {text}
            </pre>
          </DisclosurePanel>
        </div>
      )}
    </Disclosure>
  )
}
