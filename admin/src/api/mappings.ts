import { apiClient } from '@/api/client'
import type {
  AyCategorySearchResponse,
  MappingCategoriesResponse,
  MappingSaveResponse,
  MappingsOverviewResponse,
} from '@/types/api'

export async function fetchMappingsOverview(signal?: AbortSignal): Promise<MappingsOverviewResponse> {
  const { data } = await apiClient.get<MappingsOverviewResponse>('/mappings/overview', { signal })
  return data
}

export async function fetchMappingCategories(signal?: AbortSignal): Promise<MappingCategoriesResponse> {
  const { data } = await apiClient.get<MappingCategoriesResponse>('/mappings/categories', { signal })
  return data
}

export async function saveMappingCategories(
  mappings: Record<string, { id: number; path: string }>,
  signal?: AbortSignal,
): Promise<MappingSaveResponse> {
  const { data } = await apiClient.post<MappingSaveResponse>('/mappings/categories', { mappings }, { signal })
  return data
}

export type AyCategoriesQuery = {
  q?: string
  parent_category?: number
  page?: number
  per_page?: number
}

export async function fetchAyCategories(params: AyCategoriesQuery = {}, signal?: AbortSignal): Promise<AyCategorySearchResponse> {
  const query: Record<string, string | number> = {}
  if (params.q?.trim()) {
    query.q = params.q.trim()
  }
  if (params.parent_category != null && params.parent_category > 0) {
    query.parent_category = params.parent_category
  }
  if (params.page != null && params.page > 0) {
    query.page = params.page
  }
  if (params.per_page != null && params.per_page > 0) {
    query.per_page = params.per_page
  }
  const { data } = await apiClient.get<AyCategorySearchResponse>('/mappings/ay-categories/search', {
    params: query,
    signal,
  })
  return data
}

