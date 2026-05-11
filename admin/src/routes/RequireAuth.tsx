import { Navigate, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuthStore } from '@/store/authStore'

export function RequireAuth() {
  const { t } = useTranslation('common')
  const token = useAuthStore((s) => s.token)
  const hydrated = useAuthStore((s) => s.hydrated)

  if (!hydrated) {
    return (
      <div className="flex min-h-screen items-center justify-center text-sm text-[var(--color-muted)]">
        {t('loading')}
      </div>
    )
  }
  if (!token) {
    return <Navigate to="/login" replace />
  }
  return <Outlet />
}
