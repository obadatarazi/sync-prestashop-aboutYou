import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react'
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/Button'

type Props = {
  open: boolean
  title: string
  description?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
  loading?: boolean
  loadingLabel?: string
  onConfirm: () => void
  onClose: () => void
}

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  danger,
  loading = false,
  loadingLabel,
  onConfirm,
  onClose,
}: Props) {
  return (
    <Dialog open={open} onClose={loading ? () => undefined : onClose} className="relative z-50">
      <DialogBackdrop className="fixed inset-0 bg-black/40 backdrop-blur-sm" />
      <div className="fixed inset-0 flex items-center justify-center p-4">
        <DialogPanel className="w-full max-w-lg rounded-2xl border border-[var(--color-border)] bg-white p-6 shadow-2xl dark:bg-zinc-950">
          <div className="flex items-start gap-3">
            {danger ? (
              <div className="mt-0.5 rounded-full bg-red-100 p-2 text-red-600 dark:bg-red-950/60 dark:text-red-300">
                <ExclamationTriangleIcon className="h-5 w-5" aria-hidden />
              </div>
            ) : null}
            <div className="min-w-0">
              <DialogTitle className="text-lg font-semibold">{title}</DialogTitle>
              {description ? <p className="mt-2 text-sm leading-6 text-[var(--color-muted)]">{description}</p> : null}
            </div>
          </div>
          <div className="mt-6 flex justify-end gap-2">
            <Button variant="secondary" type="button" onClick={onClose} disabled={loading}>
              {cancelLabel}
            </Button>
            <Button variant={danger ? 'danger' : 'primary'} type="button" onClick={onConfirm} disabled={loading}>
              {loading ? (loadingLabel ?? confirmLabel) : confirmLabel}
            </Button>
          </div>
        </DialogPanel>
      </div>
    </Dialog>
  )
}
