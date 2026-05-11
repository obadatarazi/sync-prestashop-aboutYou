import { useCallback, useEffect, useState } from 'react'

export function useTableColumnPrefs(tableId: string, defaultVisible: string[]) {
  const key = `syncbridge_table_cols_${tableId}`
  const [visible, setVisible] = useState<string[]>(() => {
    try {
      const raw = localStorage.getItem(key)
      if (raw) {
        const parsed = JSON.parse(raw) as unknown
        if (Array.isArray(parsed) && parsed.every((x) => typeof x === 'string')) return parsed
      }
    } catch {
      /* ignore */
    }
    return defaultVisible
  })

  useEffect(() => {
    try {
      localStorage.setItem(key, JSON.stringify(visible))
    } catch {
      /* ignore */
    }
  }, [key, visible])

  const toggle = useCallback((id: string) => {
    setVisible((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]))
  }, [])

  return { visible, setVisible, toggle }
}
