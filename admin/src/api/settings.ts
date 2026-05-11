import { apiClient } from '@/api/client'
import type { SettingsListResponse, SettingsSaveResponse } from '@/types/api'

export async function fetchSettings(signal?: AbortSignal): Promise<SettingsListResponse> {
  const { data } = await apiClient.get<SettingsListResponse>('/settings', { signal })
  return data
}

export async function saveSettings(
  settings: Record<string, string>,
  signal?: AbortSignal,
): Promise<SettingsSaveResponse> {
  const { data } = await apiClient.post<SettingsSaveResponse>(
    '/settings',
    { settings },
    { signal },
  )
  return data
}
