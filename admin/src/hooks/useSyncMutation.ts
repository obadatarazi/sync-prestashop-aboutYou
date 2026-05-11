import { useMutation, useQueryClient } from '@tanstack/react-query'
import { executeProductsSync, executeSync } from '@/services/syncService'
import type { SyncProductsRequest, SyncRunRequest } from '@/types/api'

export function useSyncRunMutation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: SyncRunRequest) => executeSync(body),
    onSettled: () => {
      void qc.invalidateQueries({ predicate: (q) => q.queryKey[0] === 'products' })
      void qc.invalidateQueries({ predicate: (q) => q.queryKey[0] === 'orders' })
    },
  })
}

export function useSyncProductsMutation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: SyncProductsRequest) => executeProductsSync(body),
    onSettled: () => {
      void qc.invalidateQueries({ predicate: (q) => q.queryKey[0] === 'products' })
    },
  })
}
