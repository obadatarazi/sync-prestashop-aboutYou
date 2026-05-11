import { useQuery } from '@tanstack/react-query'
import { qk } from '@/hooks/queryKeys'
import { getOrder } from '@/services/ordersService'

export function useOrderQuery(id: number | undefined) {
  return useQuery({
    queryKey: id ? qk.orders.detail(id) : ['orders', 'detail', 'none'],
    queryFn: ({ signal }) => getOrder(id!, signal),
    enabled: typeof id === 'number' && id > 0,
  })
}
