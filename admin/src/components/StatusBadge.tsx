import { Badge } from '@/components/ui/Badge'
import type { ProductSyncStatus } from '@/types/api'

const productMap: Record<ProductSyncStatus, { label: string; tone: 'success' | 'warning' | 'danger' | 'info' | 'default' }> = {
  synced: { label: 'Synced', tone: 'success' },
  pending: { label: 'Pending', tone: 'warning' },
  error: { label: 'Failed', tone: 'danger' },
  syncing: { label: 'Retrying', tone: 'info' },
  quarantined: { label: 'Quarantined', tone: 'danger' },
}

export function ProductStatusBadge({ status }: { status: ProductSyncStatus }) {
  const m = productMap[status] ?? { label: status, tone: 'default' as const }
  return <Badge tone={m.tone}>{m.label}</Badge>
}

const orderTone = (s: string): 'success' | 'warning' | 'danger' | 'info' | 'default' => {
  if (s === 'imported' || s === 'synced') return 'success'
  if (s === 'failed' || s === 'error') return 'danger'
  if (s === 'processing' || s === 'syncing') return 'info'
  if (s === 'quarantined') return 'warning'
  return 'default'
}

export function OrderStatusBadge({ status }: { status: string }) {
  return <Badge tone={orderTone(status)}>{status}</Badge>
}
