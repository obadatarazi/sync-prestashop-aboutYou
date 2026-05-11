import { useEffect } from 'react'
import { useUiStore } from '@/store/uiStore'

export function useKeyboardShortcuts() {
  const setPalette = useUiStore((s) => s.setCommandPaletteOpen)
  const toggleSidebar = useUiStore((s) => s.toggleSidebar)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const mod = e.metaKey || e.ctrlKey
      if (mod && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setPalette(true)
      }
      if (mod && e.key.toLowerCase() === 'b') {
        e.preventDefault()
        toggleSidebar()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [setPalette, toggleSidebar])
}
