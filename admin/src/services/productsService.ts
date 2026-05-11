import {
  fetchProduct,
  fetchProducts,
  previewProductPayload,
  refetchProductsFromPrestaShop,
  type ProductListParams,
  updateProductDraft,
} from '@/api/products'
import type {
  ProductDetailResponse,
  ProductDraftUpdateRequest,
  ProductDraftUpdateResponse,
  ProductPayloadPreviewResponse,
  ProductRefetchResponse,
  ProductsListResponse,
} from '@/types/api'

export async function getProducts(params: ProductListParams): Promise<ProductsListResponse> {
  return fetchProducts(params)
}

export async function getProduct(id: number, signal?: AbortSignal): Promise<ProductDetailResponse> {
  return fetchProduct(id, signal)
}

export async function saveProductDraft(
  id: number,
  payload: ProductDraftUpdateRequest,
  signal?: AbortSignal,
): Promise<ProductDraftUpdateResponse> {
  return updateProductDraft(id, payload, signal)
}

export async function getProductPayloadPreview(
  id: number,
  signal?: AbortSignal,
): Promise<ProductPayloadPreviewResponse> {
  return previewProductPayload(id, signal)
}

export async function refetchProducts(
  payload?: { ps_product_ids?: number[] },
  signal?: AbortSignal,
): Promise<ProductRefetchResponse> {
  return refetchProductsFromPrestaShop(payload, signal)
}
