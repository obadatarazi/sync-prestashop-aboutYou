import { Component, type ErrorInfo, type ReactNode } from 'react'
import i18n from '@/lib/i18n'
import { Button } from '@/components/ui/Button'

type Props = { children: ReactNode; fallbackTitle?: string }

type State = { hasError: boolean; error: Error | null }

export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false, error: null }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('ErrorBoundary', error, info)
  }

  render() {
    if (this.state.hasError && this.state.error) {
      return (
        <div className="flex min-h-[40vh] flex-col items-center justify-center gap-4 p-8 text-center">
          <h2 className="text-lg font-semibold">{this.props.fallbackTitle ?? i18n.t('ui.somethingBroke')}</h2>
          <p className="max-w-md text-sm text-[var(--color-muted)]">{this.state.error.message}</p>
          <Button type="button" onClick={() => this.setState({ hasError: false, error: null })}>
            {i18n.t('ui.tryAgain')}
          </Button>
        </div>
      )
    }
    return this.props.children
  }
}
