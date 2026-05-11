import { z } from 'zod'

const commands = [
  'products',
  'products:inc',
  'stock',
  'orders',
  'order-status',
  'all',
  'retry',
  'status',
] as const

export const syncFormSchema = z.object({
  command: z.enum(commands),
  since: z.string().optional(),
  ps_product_ids_text: z.string().optional(),
})

export type SyncFormInput = z.infer<typeof syncFormSchema>

export function parsePsIds(text: string | undefined): number[] {
  if (!text?.trim()) return []
  return text
    .split(/[\s,;]+/)
    .map((x) => Number.parseInt(x.trim(), 10))
    .filter((n) => Number.isFinite(n) && n > 0)
}

export function buildSyncPayload(parsed: SyncFormInput) {
  const ps_product_ids = parsePsIds(parsed.ps_product_ids_text)
  return {
    command: parsed.command,
    since: parsed.since?.trim() || undefined,
    ps_product_ids: ps_product_ids.length > 0 ? ps_product_ids : undefined,
  }
}
