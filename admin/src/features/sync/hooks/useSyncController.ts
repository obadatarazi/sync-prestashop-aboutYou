import { useState } from 'react'
import type { UseFormReturn } from 'react-hook-form'
import { normalizeAxiosError } from '@/api/errors'
import { useSyncRunMutation } from '@/hooks/useSyncMutation'
import { buildSyncPayload, type SyncFormInput } from '@/schemas/syncForm'
import { toastError, toastSuccess } from '@/store/toastStore'
import type { SyncCommand } from '@/types/api'

export function useSyncController(form: UseFormReturn<SyncFormInput>) {
  const syncRun = useSyncRunMutation()
  const [lastResult, setLastResult] = useState<unknown>(null)

  const runBody = (body: ReturnType<typeof buildSyncPayload>) => {
    syncRun.mutate(body, {
      onSuccess: (res) => {
        setLastResult(res)
        toastSuccess('Sync', res.message ?? String(body.command))
      },
      onError: (e) => toastError('Sync failed', normalizeAxiosError(e)),
    })
  }

  const onSubmit = (values: SyncFormInput) => {
    runBody(buildSyncPayload(values))
  }

  const presetRun = (command: SyncCommand) => {
    const values = { ...form.getValues(), command }
    form.setValue('command', command)
    runBody(buildSyncPayload(values))
  }

  return { syncRun, lastResult, onSubmit, presetRun }
}
