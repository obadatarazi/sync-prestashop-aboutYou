import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Helmet } from 'react-helmet-async'
import { Navigate, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { useLoginController } from '@/features/auth/hooks/useLoginController'
import { useAuthStore } from '@/store/authStore'

export default function LoginPage() {
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const navigate = useNavigate()
  const token = useAuthStore((s) => s.token)
  const hydrated = useAuthStore((s) => s.hydrated)
  const { form, submit } = useLoginController()

  useEffect(() => {
    useAuthStore.getState().hydrate()
  }, [])

  if (hydrated && token) {
    return <Navigate to="/" replace />
  }

  const onSubmit = (v: { token: string; remember: boolean }) => {
    submit(v)
    navigate('/', { replace: true })
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-[var(--color-surface)] p-4">
      <Helmet>
        <title>{tn('login')} — {t('appName')}</title>
      </Helmet>
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>
            {tn('login')} — {t('appName')}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={form.handleSubmit(onSubmit)} noValidate>
            <div>
              <label className="mb-1 block text-xs font-medium text-[var(--color-muted)]" htmlFor="token">
                {t('apiToken', { ns: 'auth' })}
              </label>
              <Input id="token" type="password" autoComplete="off" {...form.register('token')} />
              {form.formState.errors.token ? (
                <p className="mt-1 text-xs text-red-600">
                  {t(String(form.formState.errors.token.message), { ns: 'errors' })}
                </p>
              ) : null}
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" {...form.register('remember')} />
              {t('rememberDevice')}
            </label>
            <Button type="submit" className="w-full">
              {t('continue')}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
