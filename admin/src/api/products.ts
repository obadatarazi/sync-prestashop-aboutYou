import { apiClient } from '@/api/client'
import type {
  ProductDetailResponse,
  ProductDraftUpdateRequest,
  ProductDraftUpdateResponse,
  ProductPayloadPreviewResponse,
  ProductRefetchResponse,
  ProductsListResponse,
  ProductSyncStatus,
} from '@/types/api'

export type ProductListParams = {
  page?: number
  per_page?: number
  status?: ProductSyncStatus
  search?: string
  signal?: AbortSignal
}

export async function fetchProducts(params: ProductListParams): Promise<ProductsListResponse> {
  const { data } = await apiClient.get<ProductsListResponse>('/products', {
    params: {
      page: params.page ?? 1,
      per_page: params.per_page ?? 20,
      status: params.status,
      search: params.search,
    },
    signal: params.signal,
  })
  return data
}

export async function fetchProduct(id: number, signal?: AbortSignal): Promise<ProductDetailResponse> {
  const { data } = await apiClient.get<ProductDetailResponse>(`/products/${id}`, { signal })
  return data
}

export async function updateProductDraft(
  id: number,
  payload: ProductDraftUpdateRequest,
  signal?: AbortSignal,
): Promise<ProductDraftUpdateResponse> {
  const { data } = await apiClient.patch<ProductDraftUpdateResponse>(`/products/${id}/draft`, payload, { signal })
  return data
}

export async function previewProductPayload(id: number, signal?: AbortSignal): Promise<ProductPayloadPreviewResponse> {
  const { data } = await apiClient.post<ProductPayloadPreviewResponse>(`/products/${id}/preview-payload`, undefined, { signal })
  return data
}

export async function refetchProductsFromPrestaShop(
  payload?: { ps_product_ids?: number[] },
  signal?: AbortSignal,
): Promise<ProductRefetchResponse> {
  const { data } = await apiClient.post<ProductRefetchResponse>('/products/refetch', payload ?? {}, { signal })
  return data
}
