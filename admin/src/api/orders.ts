import { apiClient } from '@/api/client'
import type { OrderDetailResponse, OrderRefetchResponse, OrderRepushResponse, OrdersListResponse, OrderUpdateRequest } from '@/types/api'

export type OrderListParams = {
  page?: number
  per_page?: number
  signal?: AbortSignal
}

export async function fetchOrders(params: OrderListParams): Promise<OrdersListResponse> {
  const { data } = await apiClient.get<OrdersListResponse>('/orders', {
    params: {
      page: params.page ?? 1,
      per_page: params.per_page ?? 20,
    },
    signal: params.signal,
  })
  return data
}

export async function fetchOrder(id: number, signal?: AbortSignal): Promise<OrderDetailResponse> {
  const { data } = await apiClient.get<OrderDetailResponse>(`/orders/${id}`, { signal })
  return data
}

export async function repushOrder(
  id: number,
  body: { include_stock_sync?: boolean } = {},
  signal?: AbortSignal,
): Promise<OrderRepushResponse> {
  const { data } = await apiClient.post<OrderRepushResponse>(`/orders/${id}/repush`, body, { signal })
  return data
}

export async function updateOrder(id: number, body: OrderUpdateRequest, signal?: AbortSignal): Promise<OrderDetailResponse> {
  const { data } = await apiClient.patch<OrderDetailResponse>(`/orders/${id}`, body, { signal })
  return data
}

export async function refetchOrdersFromAboutYou(
  body: { since?: string } = {},
  signal?: AbortSignal,
): Promise<OrderRefetchResponse> {
  const { data } = await apiClient.post<OrderRefetchResponse>('/orders/refetch', body, { signal })
  return data
}
