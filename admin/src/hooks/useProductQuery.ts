import { useQuery } from '@tanstack/react-query'
import { qk } from '@/hooks/queryKeys'
import { getProduct } from '@/services/productsService'

export function useProductQuery(id: number | undefined) {
  return useQuery({
    queryKey: id ? qk.products.detail(id) : ['products', 'detail', 'none'],
    queryFn: ({ signal }) => getProduct(id!, signal),
    enabled: typeof id === 'number' && id > 0,
  })
}
