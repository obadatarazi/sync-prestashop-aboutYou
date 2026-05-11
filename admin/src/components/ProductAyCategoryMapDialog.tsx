import { useEffect, useState } from 'react'
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { AyCategoryCascadePicker } from '@/components/AyCategoryCascadePicker'
import { Button } from '@/components/ui/Button'
import { normalizeAxiosError } from '@/api/errors'
import { saveProductDraft } from '@/services/productsService'
import { toastError, toastSuccess } from '@/store/toastStore'
import { qk } from '@/hooks/queryKeys'
import type { AyCategorySearchItem } from '@/types/api'

type Props = {
  open: boolean
  onClose: () => void
  productId: number
  roots: AyCategorySearchItem[]
  rootsLoading: boolean
  rootsError: boolean
}

export function ProductAyCategoryMapDialog({ open, onClose, productId, roots, rootsLoading, rootsError }: Props) {
  const { t } = useTranslation('common')
  const { t: tp } = useTranslation('products')
  const qc = useQueryClient()
  const [pickerNonce, setPickerNonce] = useState(0)

  useEffect(() => {
    if (open) {
      setPickerNonce((n) => n + 1)
      void qc.refetchQueries({ queryKey: qk.mappings.ayCategoryRoots() })
    }
  }, [open, qc])

  const mutation = useMutation({
    mutationFn: (mapping: { id: number; path: string }) =>
      saveProductDraft(productId, {
        ay_category_id: mapping.id,
        ay_category_path: mapping.path,
      }),
    onSuccess: () => {
      toastSuccess(tp('ayCategorySaved'))
      void qc.invalidateQueries({ queryKey: ['products', 'list'] })
      void qc.invalidateQueries({ queryKey: qk.products.detail(productId) })
      onClose()
    },
    onError: (e) => toastError(tp('ayCategorySaveError'), normalizeAxiosError(e)),
  })

  return (
    <Dialog open={open} onClose={onClose} className="relative z-50">
      <DialogBackdrop className="fixed inset-0 bg-black/40 backdrop-blur-sm" />
      <div className="fixed inset-0 flex items-center justify-center p-4">
        <DialogPanel className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)] p-6 shadow-2xl">
          <DialogTitle className="text-lg font-semibold">{tp('ayCategoryMapTitle')}</DialogTitle>
          <p className="mt-2 text-sm text-[var(--color-muted)]">{tp('ayCategoryMapHint')}</p>
          <div className="mt-4">
            {open ? (
              <AyCategoryCascadePicker
                key={pickerNonce}
                rowKey={pickerNonce}
                roots={roots}
                rootsLoading={rootsLoading}
                rootsError={rootsError}
                onSelect={(m) => mutation.mutate(m)}
              />
            ) : null}
          </div>
          <div className="mt-6 flex justify-end gap-2">
            <Button variant="secondary" type="button" disabled={mutation.isPending} onClick={onClose}>
              {t('cancel')}
            </Button>
          </div>
        </DialogPanel>
      </div>
    </Dialog>
  )
}
