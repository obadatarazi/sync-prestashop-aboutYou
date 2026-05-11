const KEY = 'syncbridge_status_product_counts'

export function pushProductCountSnapshot(count: number): number[] {
  try {
    const prev = JSON.parse(sessionStorage.getItem(KEY) ?? '[]') as unknown
    const arr = Array.isArray(prev) && prev.every((x) => typeof x === 'number') ? [...prev, count] : [count]
    const trimmed = arr.slice(-24)
    sessionStorage.setItem(KEY, JSON.stringify(trimmed))
    return trimmed
  } catch {
    return [count]
  }
}

export function readProductCountHistory(): number[] {
  try {
    const prev = JSON.parse(sessionStorage.getItem(KEY) ?? '[]') as unknown
    return Array.isArray(prev) && prev.every((x) => typeof x === 'number') ? prev : []
  } catch {
    return []
  }
}
