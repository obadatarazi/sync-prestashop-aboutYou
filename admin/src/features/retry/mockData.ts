export type RetryJobType = 'product_push' | 'order_import' | 'order_status'

export type MockRetryJob = {
  id: string
  type: RetryJobType
  entityKey: string
  attempts: number
  status: 'pending' | 'dead' | 'done'
  lastError?: string
  payloadPreview: string
}

export const mockRetryJobs: MockRetryJob[] = [
  {
    id: 'rj-1',
    type: 'product_push',
    entityKey: '102938',
    attempts: 2,
    status: 'pending',
    lastError: 'validation_failed',
    payloadPreview: '{"ps_id":102938}',
  },
  {
    id: 'rj-2',
    type: 'order_import',
    entityKey: 'AY-998877',
    attempts: 5,
    status: 'dead',
    lastError: 'max_attempts',
    payloadPreview: '{"ay_order_id":"AY-998877"}',
  },
]
