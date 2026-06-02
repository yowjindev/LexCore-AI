'use client'

import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/hooks/useAuth'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { Sidebar } from './Sidebar'
import { Header } from './Header'

export function DashboardShell({ children }: { children: React.ReactNode }) {
  const { isPending } = useAuth()
  const queryClient = useQueryClient()

  useEffect(() => {
    // Force all active queries to immediately refetch when navigating back/forward
    // or restoring from the browser's back/forward cache (bfcache).
    // { refetchType: 'all' } triggers an active network request right now
    // rather than just marking data as stale for the next render cycle.
    const refetchAll = (): void => {
      queryClient.invalidateQueries({ refetchType: 'all' })
    }

    const handlePageShow = (event: PageTransitionEvent): void => {
      if (event.persisted) refetchAll()   // bfcache restore
    }

    window.addEventListener('popstate', refetchAll)
    window.addEventListener('pageshow', handlePageShow)

    return () => {
      window.removeEventListener('popstate', refetchAll)
      window.removeEventListener('pageshow', handlePageShow)
    }
  }, [queryClient])

  if (isPending) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <LoadingSpinner />
      </div>
    )
  }

  return (
    <div className="flex h-screen overflow-hidden bg-background">
      <Sidebar />
      <div className="flex flex-1 flex-col overflow-hidden">
        <Header />
        <main className="flex-1 overflow-y-auto p-6">{children}</main>
      </div>
    </div>
  )
}
