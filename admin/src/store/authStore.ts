import { create } from 'zustand'

const SESSION_KEY = 'syncbridge_token_session'
const LOCAL_KEY = 'syncbridge_token_local'

function readStoredToken(): string | null {
  try {
    return sessionStorage.getItem(SESSION_KEY) || localStorage.getItem(LOCAL_KEY)
  } catch {
    return null
  }
}

type AuthState = {
  token: string | null
  hydrated: boolean
  hydrate: () => void
  login: (token: string, remember: boolean) => void
  logout: () => void
}

export const useAuthStore = create<AuthState>((set) => ({
  token: null,
  hydrated: false,
  hydrate: () => {
    set({ token: readStoredToken(), hydrated: true })
  },
  login: (token, remember) => {
    try {
      sessionStorage.removeItem(SESSION_KEY)
      localStorage.removeItem(LOCAL_KEY)
      if (remember) localStorage.setItem(LOCAL_KEY, token)
      else sessionStorage.setItem(SESSION_KEY, token)
    } catch {
      /* ignore quota */
    }
    set({ token })
  },
  logout: () => {
    try {
      sessionStorage.removeItem(SESSION_KEY)
      localStorage.removeItem(LOCAL_KEY)
    } catch {
      /* ignore */
    }
    set({ token: null })
  },
}))

export function getStoredToken(): string | null {
  return readStoredToken()
}
