import { lazy } from 'react'
import { createBrowserRouter, Navigate } from 'react-router-dom'
import { AppShell } from '@/layouts/AppShell'
import LoginPage from '@/pages/LoginPage'
import { RequireAuth } from '@/routes/RequireAuth'
import { RouteError } from '@/routes/RouteError'

const DashboardPage = lazy(() => import('@/pages/DashboardPage'))
const ProductsPage = lazy(() => import('@/pages/ProductsPage'))
const ProductDetailPage = lazy(() => import('@/pages/ProductDetailPage'))
const OrdersPage = lazy(() => import('@/pages/OrdersPage'))
const OrderDetailPage = lazy(() => import('@/pages/OrderDetailPage'))
const SyncPage = lazy(() => import('@/pages/SyncPage'))
const SettingsPage = lazy(() => import('@/pages/SettingsPage'))
const LogsPage = lazy(() => import('@/pages/LogsPage'))
const RetryQueuePage = lazy(() => import('@/pages/RetryQueuePage'))
const MappingsPage = lazy(() => import('@/pages/MappingsPage'))
const ImageDiagnosticsPage = lazy(() => import('@/pages/ImageDiagnosticsPage'))

export function createAppRouter() {
  return createBrowserRouter([
    { path: '/login', element: <LoginPage /> },
    {
      path: '/',
      element: <RequireAuth />,
      children: [
        {
          element: <AppShell />,
          errorElement: <RouteError />,
          children: [
            { index: true, element: <DashboardPage /> },
            { path: 'products', element: <ProductsPage /> },
            { path: 'products/:id', element: <ProductDetailPage /> },
            { path: 'orders', element: <OrdersPage /> },
            { path: 'orders/:id', element: <OrderDetailPage /> },
            { path: 'sync', element: <SyncPage /> },
            { path: 'settings', element: <SettingsPage /> },
            { path: 'logs', element: <LogsPage /> },
            { path: 'retry', element: <RetryQueuePage /> },
            { path: 'mappings', element: <MappingsPage /> },
            { path: 'images', element: <ImageDiagnosticsPage /> },
          ],
        },
      ],
    },
    { path: '*', element: <Navigate to="/" replace /> },
  ])
}
