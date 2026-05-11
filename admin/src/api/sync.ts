import { apiClient } from '@/api/client'
import type { SyncProductsRequest, SyncRunRequest, SyncRunResponse } from '@/types/api'

export async function runSync(body: SyncRunRequest, signal?: AbortSignal): Promise<SyncRunResponse> {
  const { data } = await apiClient.post<SyncRunResponse>('/sync', body, { signal })
  return data
}

export async function runSyncProducts(
  body: SyncProductsRequest,
  signal?: AbortSignal,
): Promise<SyncRunResponse> {
  const { data } = await apiClient.post<SyncRunResponse>('/sync/products', body, { signal })
  return data
}
