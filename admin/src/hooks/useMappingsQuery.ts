import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchAyCategories,
  fetchMappingCategories,
  fetchMappingsOverview,
  saveMappingCategories,
} from '@/api/mappings'
import { qk } from '@/hooks/queryKeys'

export function useMappingsOverviewQuery() {
  return useQuery({
    queryKey: qk.mappings.overview(),
    queryFn: ({ signal }) => fetchMappingsOverview(signal),
  })
}

export function useMappingCategoriesQuery() {
  return useQuery({
    queryKey: qk.mappings.categories(),
    queryFn: ({ signal }) => fetchMappingCategories(signal),
  })
}

export function useSaveMappingCategoriesMutation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (mappings: Record<string, { id: number; path: string }>) => saveMappingCategories(mappings),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: qk.mappings.categories() })
      void qc.invalidateQueries({ queryKey: qk.mappings.overview() })
    },
  })
}

export function useAyCategoryRootsQuery() {
  return useQuery({
    queryKey: qk.mappings.ayCategoryRoots(),
    queryFn: ({ signal }) => fetchAyCategories({ per_page: 100 }, signal),
    staleTime: 30 * 60 * 1000,
  })
}

