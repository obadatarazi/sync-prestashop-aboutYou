import { apiClient } from '@/api/client'
import type {
  ImageDiagnosticsResponse,
  ImageGalleryResponse,
  NormalizeImagesResponse,
} from '@/types/api'

export async function fetchImageDiagnostics(
  opts: { includeSamples?: boolean; sampleLimit?: number; signal?: AbortSignal },
): Promise<ImageDiagnosticsResponse> {
  const { data } = await apiClient.get<ImageDiagnosticsResponse>('/diagnostics/images', {
    signal: opts.signal,
    params: {
      include_samples: opts.includeSamples ? 1 : 0,
      sample_limit: opts.sampleLimit ?? 12,
    },
  })
  return data
}

export async function fetchImageDiagnosticsGallery(
  opts: { filter: 'problematic' | 'all'; limit?: number; signal?: AbortSignal },
): Promise<ImageGalleryResponse> {
  const { data } = await apiClient.get<ImageGalleryResponse>('/diagnostics/images/gallery', {
    signal: opts.signal,
    params: { filter: opts.filter, limit: opts.limit ?? 18 },
  })
  return data
}

export async function postNormalizeProductImages(
  body: { product_ids?: number[]; mode?: 'problematic'; limit?: number },
  signal?: AbortSignal,
): Promise<NormalizeImagesResponse> {
  const { data } = await apiClient.post<NormalizeImagesResponse>('/diagnostics/images/normalize', body, { signal })
  return data
}
