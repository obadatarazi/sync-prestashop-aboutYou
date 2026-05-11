import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchImageDiagnostics, fetchImageDiagnosticsGallery, postNormalizeProductImages } from '@/api/diagnostics'
import { qk } from '@/hooks/queryKeys'

export function useImageDiagnosticsQuery(includeSamples = false) {
  return useQuery({
    queryKey: qk.diagnostics.images({ includeSamples }),
    queryFn: ({ signal }) =>
      fetchImageDiagnostics({ includeSamples, sampleLimit: 12, signal }),
    staleTime: 30_000,
  })
}

export function useImageDiagnosticsGalleryQuery(filter: 'problematic' | 'all', limit = 18) {
  return useQuery({
    queryKey: qk.diagnostics.imagesGallery({ filter, limit }),
    queryFn: ({ signal }) => fetchImageDiagnosticsGallery({ filter, limit, signal }),
    staleTime: 15_000,
  })
}

export function useNormalizeImagesMutation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (body: { product_ids?: number[]; mode?: 'problematic'; limit?: number }) =>
      postNormalizeProductImages(body),
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: ['diagnostics', 'images'] })
    },
  })
}
