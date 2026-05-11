import { fetchSettings, saveSettings } from '@/api/settings'
import type { SettingsListResponse, SettingsSaveResponse } from '@/types/api'

export async function getSettings(signal?: AbortSignal): Promise<SettingsListResponse> {
  return fetchSettings(signal)
}

export async function updateSettings(
  settings: Record<string, string>,
  signal?: AbortSignal,
): Promise<SettingsSaveResponse> {
  return saveSettings(settings, signal)
}
