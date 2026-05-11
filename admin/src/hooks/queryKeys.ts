export const qk = {
  products: {
    list: (p: { page: number; per_page: number; status?: string; search?: string }) =>
      ['products', 'list', p] as const,
    detail: (id: number) => ['products', 'detail', id] as const,
  },
  orders: {
    list: (p: { page: number; per_page: number }) => ['orders', 'list', p] as const,
    detail: (id: number) => ['orders', 'detail', id] as const,
  },
  settings: {
    list: () => ['settings'] as const,
  },
  mappings: {
    overview: () => ['mappings', 'overview'] as const,
    categories: () => ['mappings', 'categories'] as const,
    ayCategoryRoots: () => ['mappings', 'ay-category-roots'] as const,
  },
  diagnostics: {
    images: (p: { includeSamples: boolean }) => ['diagnostics', 'images', p] as const,
    imagesGallery: (p: { filter: string; limit: number }) => ['diagnostics', 'images', 'gallery', p] as const,
  },
}
