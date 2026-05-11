import { useQuery } from '@tanstack/react-query'
import { qk } from '@/hooks/queryKeys'
import { getOrders } from '@/services/ordersService'

export function useOrdersQuery(params: { page: number; per_page: number; enabled?: boolean }) {
  return useQuery({
    queryKey: qk.orders.list({ page: params.page, per_page: params.per_page }),
    queryFn: ({ signal }) =>
      getOrders({
        page: params.page,
        per_page: params.per_page,
        signal,
      }),
    enabled: params.enabled !== false,
  })
}
