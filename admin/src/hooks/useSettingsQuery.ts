import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { qk } from '@/hooks/queryKeys'
import { getSettings, updateSettings } from '@/services/settingsService'

export function useSettingsQuery(enabled = true) {
  return useQuery({
    queryKey: qk.settings.list(),
    queryFn: ({ signal }) => getSettings(signal),
    enabled,
  })
}

export function useSaveSettingsMutation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (settings: Record<string, string>) => updateSettings(settings),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.settings.list() })
    },
  })
}
