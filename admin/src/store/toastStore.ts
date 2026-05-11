import { create } from 'zustand'

export type ToastKind = 'success' | 'error' | 'info'

export type Toast = {
  id: string
  title: string
  description?: string
  kind: ToastKind
}

type ToastState = {
  toasts: Toast[]
  push: (t: Omit<Toast, 'id'>) => void
  dismiss: (id: string) => void
}

export const useToastStore = create<ToastState>((set) => ({
  toasts: [],
  push: (t) => {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
    set((s) => ({ toasts: [...s.toasts, { ...t, id }] }))
    window.setTimeout(() => {
      set((s) => ({ toasts: s.toasts.filter((x) => x.id !== id) }))
    }, 6000)
  },
  dismiss: (id) => set((s) => ({ toasts: s.toasts.filter((x) => x.id !== id) })),
}))

export function toastSuccess(title: string, description?: string) {
  useToastStore.getState().push({ kind: 'success', title, description })
}

export function toastError(title: string, description?: string) {
  useToastStore.getState().push({ kind: 'error', title, description })
}

export function toastInfo(title: string, description?: string) {
  useToastStore.getState().push({ kind: 'info', title, description })
}
