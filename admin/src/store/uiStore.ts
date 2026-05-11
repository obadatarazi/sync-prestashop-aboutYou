import { create } from 'zustand'

export type ThemeMode = 'light' | 'dark' | 'system'

const THEME_KEY = 'syncbridge_theme'

function applyThemeClass(mode: ThemeMode) {
  const root = document.documentElement
  let dark = false
  if (mode === 'dark') dark = true
  else if (mode === 'system') {
    dark = window.matchMedia('(prefers-color-scheme: dark)').matches
  }
  root.classList.toggle('dark', dark)
}

type UiState = {
  sidebarOpen: boolean
  sidebarCollapsed: boolean
  commandPaletteOpen: boolean
  language: 'en' | 'ar'
  theme: ThemeMode
  setSidebarOpen: (v: boolean) => void
  toggleSidebar: () => void
  setSidebarCollapsed: (v: boolean) => void
  setCommandPaletteOpen: (v: boolean) => void
  setLanguage: (language: 'en' | 'ar') => void
  setTheme: (mode: ThemeMode) => void
  initTheme: () => void
}

export const useUiStore = create<UiState>((set, get) => ({
  sidebarOpen: false,
  sidebarCollapsed: false,
  commandPaletteOpen: false,
  language: 'en',
  theme: 'system',
  setSidebarOpen: (sidebarOpen) => set({ sidebarOpen }),
  toggleSidebar: () => set({ sidebarOpen: !get().sidebarOpen }),
  setSidebarCollapsed: (sidebarCollapsed) => set({ sidebarCollapsed }),
  setCommandPaletteOpen: (commandPaletteOpen) => set({ commandPaletteOpen }),
  setLanguage: (language) => set({ language }),
  setTheme: (theme) => {
    try {
      localStorage.setItem(THEME_KEY, theme)
    } catch {
      /* ignore */
    }
    applyThemeClass(theme)
    set({ theme })
  },
  initTheme: () => {
    let stored: ThemeMode = 'system'
    try {
      const r = localStorage.getItem(THEME_KEY) as ThemeMode | null
      if (r === 'light' || r === 'dark' || r === 'system') stored = r
    } catch {
      /* ignore */
    }
    applyThemeClass(stored)
    set({ theme: stored })
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (get().theme === 'system') applyThemeClass('system')
    })
  },
}))
