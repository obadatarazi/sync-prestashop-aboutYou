import axios, { type AxiosInstance } from 'axios'
import { useAuthStore } from '@/store/authStore'
import { ApiApplicationError } from '@/api/errors'
import type { ApiErrorBody } from '@/types/api'

function getBaseURL(): string {
  const u = import.meta.env.VITE_API_BASE_URL
  if (!u) {
    console.warn('VITE_API_BASE_URL is not set')
    return '/api/v1'
  }
  return u.replace(/\/$/, '')
}

function attachAuth(config: { headers?: Record<string, string> }) {
  const token = useAuthStore.getState().token
  if (!token) return
  const mode = import.meta.env.VITE_API_TOKEN_HEADER ?? 'bearer'
  if (mode === 'x-api-token') {
    config.headers = { ...config.headers, 'X-Api-Token': token }
  } else {
    config.headers = { ...config.headers, Authorization: `Bearer ${token}` }
  }
}

export function createApiClient(): AxiosInstance {
  const client = axios.create({
    baseURL: getBaseURL(),
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    timeout: 0,
  })

  client.interceptors.request.use((config) => {
    attachAuth(config as { headers?: Record<string, string> })
    return config
  })

  client.interceptors.response.use(
    (response) => {
      const data = response.data as { ok?: boolean; error?: string; errors?: Record<string, string[]> }
      if (data && typeof data === 'object' && 'ok' in data && data.ok === false) {
        return Promise.reject(new ApiApplicationError(data.error ?? 'Request failed', data.errors))
      }
      return response
    },
    (error) => {
      if (error.response?.status === 401) {
        useAuthStore.getState().logout()
      }
      return Promise.reject(error)
    },
  )

  return client
}

export const apiClient = createApiClient()

export function getErrorPayload(err: unknown): ApiErrorBody | null {
  const res = (err as { response?: { data?: ApiErrorBody } })?.response?.data
  if (res && res.ok === false) return res
  return null
}
