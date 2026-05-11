import { QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { HelmetProvider } from 'react-helmet-async'
import { RouterProvider } from 'react-router-dom'
import { TooltipProvider } from '@/components/TooltipProvider'
import '@/index.css'
import '@/lib/i18n'
import { createQueryClient } from '@/lib/queryClient'
import { createAppRouter } from '@/routes/router'
import { useAuthStore } from '@/store/authStore'
import { useUiStore } from '@/store/uiStore'

useAuthStore.getState().hydrate()
useUiStore.getState().initTheme()

const queryClient = createQueryClient()
const router = createAppRouter()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <HelmetProvider>
      <QueryClientProvider client={queryClient}>
        <TooltipProvider>
          <RouterProvider router={router} />
        </TooltipProvider>
      </QueryClientProvider>
    </HelmetProvider>
  </StrictMode>,
)
