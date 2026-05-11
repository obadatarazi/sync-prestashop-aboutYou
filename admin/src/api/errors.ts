import type { AxiosError } from 'axios'
import type { ApiErrorBody } from '@/types/api'

export class ApiApplicationError extends Error {
  readonly errors?: Record<string, string[]>

  constructor(message: string, errors?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiApplicationError'
    this.errors = errors
  }
}

export function normalizeAxiosError(err: unknown): string {
  if (err instanceof ApiApplicationError) return err.message
  const ax = err as AxiosError<ApiErrorBody>
  if (ax.response?.data?.error) return ax.response.data.error
  if (ax.message) return ax.message
  if (err instanceof Error) return err.message
  return 'Unknown error'
}

export function isAxiosError(err: unknown): err is AxiosError {
  return typeof err === 'object' && err !== null && 'isAxiosError' in err
}
