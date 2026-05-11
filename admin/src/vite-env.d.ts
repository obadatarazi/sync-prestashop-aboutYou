/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string
  readonly VITE_API_TOKEN?: string
  readonly VITE_API_TOKEN_HEADER?: 'bearer' | 'x-api-token'
  readonly VITE_REQUIRE_TOKEN_FOR_READ?: string
  readonly VITE_USE_MOCK_RUN_HISTORY?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
