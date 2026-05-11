import { useRouteError, isRouteErrorResponse, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'

export function RouteError() {
  const nav = useNavigate()
  const err = useRouteError()
  let message = 'Unexpected error'
  if (isRouteErrorResponse(err)) message = err.statusText || String(err.status)
  else if (err instanceof Error) message = err.message

  return (
    <div className="flex min-h-[50vh] flex-col items-center justify-center gap-4 p-8 text-center">
      <h1 className="text-lg font-semibold">Route error</h1>
      <p className="max-w-md text-sm text-[var(--color-muted)]">{message}</p>
      <Button variant="secondary" type="button" onClick={() => nav('/')}>
        Back home
      </Button>
    </div>
  )
}
