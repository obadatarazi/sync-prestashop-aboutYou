import { fetchOrder, fetchOrders, refetchOrdersFromAboutYou, repushOrder, updateOrder, type OrderListParams } from '@/api/orders'
import type { OrderDetailResponse, OrderRefetchResponse, OrderRepushResponse, OrdersListResponse, OrderUpdateRequest } from '@/types/api'

export async function getOrders(params: OrderListParams): Promise<OrdersListResponse> {
  return fetchOrders(params)
}

export async function getOrder(id: number, signal?: AbortSignal): Promise<OrderDetailResponse> {
  return fetchOrder(id, signal)
}

export async function runOrderRepush(
  id: number,
  includeStockSync = true,
  signal?: AbortSignal,
): Promise<OrderRepushResponse> {
  return repushOrder(id, { include_stock_sync: includeStockSync }, signal)
}

export async function saveOrderDetails(id: number, body: OrderUpdateRequest, signal?: AbortSignal): Promise<OrderDetailResponse> {
  return updateOrder(id, body, signal)
}

export async function refetchOrders(body: { since?: string } = {}, signal?: AbortSignal): Promise<OrderRefetchResponse> {
  return refetchOrdersFromAboutYou(body, signal)
}
