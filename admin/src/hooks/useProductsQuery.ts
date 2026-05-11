import { useQuery } from '@tanstack/react-query'
import { qk } from '@/hooks/queryKeys'
import { getProducts } from '@/services/productsService'
import type { ProductSyncStatus } from '@/types/api'

export function useProductsQuery(params: {
  page: number
  per_page: number
  status?: ProductSyncStatus
  search: string
  enabled?: boolean
}) {
  return useQuery({
    queryKey: qk.products.list({
      page: params.page,
      per_page: params.per_page,
      status: params.status,
      search: params.search,
    }),
    queryFn: ({ signal }) =>
      getProducts({
        page: params.page,
        per_page: params.per_page,
        status: params.status,
        search: params.search || undefined,
        signal,
      }),
    enabled: params.enabled !== false,
  })
}
