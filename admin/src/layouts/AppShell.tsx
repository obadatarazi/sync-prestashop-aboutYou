import {
  Dialog,
  DialogBackdrop,
  DialogPanel,
  Menu,
  MenuButton,
  MenuItem,
  MenuItems,
} from '@headlessui/react'
import {
  ArrowRightOnRectangleIcon,
  Bars3Icon,
  ChevronDownIcon,
  CommandLineIcon,
  Cog6ToothIcon,
  LanguageIcon,
  CubeIcon,
  PhotoIcon,
  QueueListIcon,
  RectangleGroupIcon,
  ShoppingBagIcon,
  SunIcon,
  MoonIcon,
  ComputerDesktopIcon,
} from '@heroicons/react/24/outline'
import { Suspense, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { AppToasts } from '@/components/AppToasts'
import { CommandPalette } from '@/components/CommandPalette'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { Button } from '@/components/ui/Button'
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts'
import { cn } from '@/lib/cn'
import { useAuthStore } from '@/store/authStore'
import { useUiStore } from '@/store/uiStore'
import type { ThemeMode } from '@/store/uiStore'

const navClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-medium transition-all',
    isActive
      ? 'bg-gradient-to-r from-indigo-600 to-violet-600 text-white shadow-sm dark:from-indigo-500 dark:to-violet-500'
      : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800',
  )

function AppShellNav() {
  const { t } = useTranslation('nav')
  return (
    <nav className="flex flex-1 flex-col gap-1 p-3">
      <NavLink to="/" className={navClass} end>
        <RectangleGroupIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('dashboard')}
      </NavLink>
      <NavLink to="/products" className={navClass}>
        <CubeIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('products')}
      </NavLink>
      <NavLink to="/orders" className={navClass}>
        <ShoppingBagIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('orders')}
      </NavLink>
      <NavLink to="/sync" className={navClass}>
        <CommandLineIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('sync')}
      </NavLink>
      <NavLink to="/settings" className={navClass}>
        <Cog6ToothIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('settings')}
      </NavLink>
      <div className="my-2 border-t border-[var(--color-border)]" />
      <NavLink to="/logs" className={navClass}>
        <QueueListIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('logs')}
      </NavLink>
      <NavLink to="/retry" className={navClass}>
        <QueueListIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('retry')}
      </NavLink>
      <NavLink to="/mappings" className={navClass}>
        <RectangleGroupIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('mappings')}
      </NavLink>
      <NavLink to="/images" className={navClass}>
        <PhotoIcon className="h-5 w-5 shrink-0 opacity-80" />
        {t('images')}
      </NavLink>
    </nav>
  )
}

export function AppShell() {
  const { t: tc, i18n } = useTranslation('common')
  const location = useLocation()
  const logout = useAuthStore((s) => s.logout)
  const sidebarOpen = useUiStore((s) => s.sidebarOpen)
  const setSidebarOpen = useUiStore((s) => s.setSidebarOpen)
  const theme = useUiStore((s) => s.theme)
  const setTheme = useUiStore((s) => s.setTheme)
  const setPalette = useUiStore((s) => s.setCommandPaletteOpen)
  const setLanguage = useUiStore((s) => s.setLanguage)

  useKeyboardShortcuts()

  useEffect(() => {
    setSidebarOpen(false)
  }, [location.pathname, setSidebarOpen])

  const themeIcon = (m: ThemeMode) => {
    if (m === 'light') return <SunIcon className="h-4 w-4" />
    if (m === 'dark') return <MoonIcon className="h-4 w-4" />
    return <ComputerDesktopIcon className="h-4 w-4" />
  }

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-64 shrink-0 border-r border-[var(--color-border)] bg-[color-mix(in_oklab,var(--color-surface-elevated)_90%,white_10%)] lg:flex lg:flex-col dark:bg-[color-mix(in_oklab,var(--color-surface-elevated)_95%,black_5%)]">
        <div className="border-b border-[var(--color-border)] px-4 py-4">
          <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[var(--color-muted)]">Ops Suite</p>
          <span className="mt-1 block text-base font-semibold tracking-tight">{tc('appName')}</span>
        </div>
        <AppShellNav />
        <div className="mt-auto border-t border-[var(--color-border)] p-3">
          <Button variant="ghost" className="w-full justify-start" type="button" onClick={() => logout()}>
            <ArrowRightOnRectangleIcon className="h-5 w-5" />
            {tc('logout')}
          </Button>
        </div>
      </aside>

      <Dialog open={sidebarOpen} onClose={() => setSidebarOpen(false)} className="relative z-50 lg:hidden">
        <DialogBackdrop className="fixed inset-0 bg-black/40" />
        <div className="fixed inset-0 flex justify-start">
          <DialogPanel className="flex h-full w-[min(100%,280px)] flex-col bg-[var(--color-surface-elevated)] shadow-xl">
            <div className="flex h-14 items-center justify-between border-b border-[var(--color-border)] px-3">
              <span className="text-sm font-semibold">{tc('appName')}</span>
              <Button variant="ghost" type="button" onClick={() => setSidebarOpen(false)}>
                {tc('close')}
              </Button>
            </div>
            <AppShellNav />
            <div className="mt-auto border-t border-[var(--color-border)] p-3">
              <Button variant="ghost" className="w-full justify-start" type="button" onClick={() => logout()}>
                <ArrowRightOnRectangleIcon className="h-5 w-5" />
                {tc('logout')}
              </Button>
            </div>
          </DialogPanel>
        </div>
      </Dialog>

      <div className="flex min-h-screen min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-50 flex h-16 items-center gap-2 border-b border-[var(--color-border)] bg-[color-mix(in_oklab,var(--color-surface)_85%,white_15%)]/95 px-4 backdrop-blur dark:bg-[color-mix(in_oklab,var(--color-surface)_92%,black_8%)]/95">
          <Button
            variant="ghost"
            type="button"
            className="lg:hidden"
            onClick={() => setSidebarOpen(true)}
            aria-label={tc('openMenu', { ns: 'ui' })}
          >
            <Bars3Icon className="h-6 w-6" />
          </Button>
          <div className="hidden sm:block">
            <p className="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">{tc('appName')}</p>
            <p className="text-xs text-[var(--color-muted)]">{tc('shellSubtitle')}</p>
          </div>
          <div className="flex flex-1 items-center justify-end gap-2">
            <Button variant="secondary" type="button" className="hidden sm:inline-flex" onClick={() => setPalette(true)}>
              <span className="text-xs text-[var(--color-muted)]">⌘K</span>
              {tc('commandPalette', { ns: 'nav', defaultValue: 'Command' })}
            </Button>
            <Menu as="div" className="relative">
              <MenuButton className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-2 py-1.5 text-sm">
                <LanguageIcon className="h-4 w-4" />
                {tc('language')}
              </MenuButton>
              <MenuItems className="absolute right-0 z-[60] mt-1 w-32 origin-top-right overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] py-1 shadow-lg focus:outline-none">
                {(['en', 'ar'] as const).map((lng) => (
                  <MenuItem key={lng}>
                    {({ focus }) => (
                      <button
                        type="button"
                        className={cn(
                          'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                          focus && 'bg-zinc-100 dark:bg-zinc-800',
                        )}
                        onClick={() => {
                          void i18n.changeLanguage(lng)
                          setLanguage(lng)
                        }}
                      >
                        {lng === 'en' ? 'English' : 'العربية'}
                      </button>
                    )}
                  </MenuItem>
                ))}
              </MenuItems>
            </Menu>
            <Menu as="div" className="relative">
              <MenuButton className="inline-flex items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-2 py-1.5 text-sm data-[hover]:bg-zinc-50 dark:data-[hover]:bg-zinc-800">
                {themeIcon(theme)}
                <ChevronDownIcon className="h-4 w-4 opacity-70" />
              </MenuButton>
              <MenuItems className="absolute right-0 z-[60] mt-1 w-44 origin-top-right overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] py-1 shadow-lg focus:outline-none">
                {(['system', 'light', 'dark'] as const).map((m) => (
                  <MenuItem key={m}>
                    {({ focus }) => (
                      <button
                        type="button"
                        className={cn(
                          'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                          focus && 'bg-zinc-100 dark:bg-zinc-800',
                        )}
                        onClick={() => setTheme(m)}
                      >
                        {themeIcon(m)}
                        {m === 'light' ? tc('themeLight') : m === 'dark' ? tc('themeDark') : tc('themeSystem')}
                      </button>
                    )}
                  </MenuItem>
                ))}
              </MenuItems>
            </Menu>
          </div>
        </header>

        <main className="flex-1 px-4 py-6 sm:px-6">
          <ErrorBoundary>
            <Suspense
              fallback={
                <div className="space-y-3">
                  <div className="h-8 w-48 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
                  <div className="h-40 animate-pulse rounded-xl bg-zinc-200 dark:bg-zinc-800" />
                </div>
              }
            >
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
        <footer className="border-t border-[var(--color-border)] bg-[var(--color-surface)]/85 px-4 py-3 text-xs text-[var(--color-muted)] sm:px-6">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p>{tc('footerLeft')}</p>
            <p>{tc('footerRight')}</p>
          </div>
        </footer>
      </div>
      <AppToasts />
      <CommandPalette />
    </div>
  )
}
