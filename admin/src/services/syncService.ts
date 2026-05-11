import { runSync, runSyncProducts } from '@/api/sync'
import type { SyncProductsRequest, SyncRunRequest, SyncRunResponse } from '@/types/api'

export async function executeSync(body: SyncRunRequest, signal?: AbortSignal): Promise<SyncRunResponse> {
  return runSync(body, signal)
}

export async function executeProductsSync(
  body: SyncProductsRequest,
  signal?: AbortSignal,
): Promise<SyncRunResponse> {
  return runSyncProducts(body, signal)
}
