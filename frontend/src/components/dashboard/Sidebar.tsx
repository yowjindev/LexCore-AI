'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import {
  LayoutDashboard,
  FileText,
  CheckSquare,
  ShieldAlert,
  Building2,
  Settings,
} from 'lucide-react'
import { cn } from '@/lib/utils'

const NAV = [
  { href: '/dashboard', icon: LayoutDashboard, label: 'Dashboard' },
  { href: '/documents', icon: FileText, label: 'Documents' },
  { href: '/tasks', icon: CheckSquare, label: 'Tasks' },
  { href: '/compliance', icon: ShieldAlert, label: 'Compliance' },
  { href: '/organization', icon: Building2, label: 'Organization' },
  { href: '/settings', icon: Settings, label: 'Settings' },
]

export function Sidebar() {
  const pathname = usePathname()

  return (
    <aside className="flex h-screen w-16 flex-col items-center border-r border-border bg-card py-4 gap-1 shrink-0">
      <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-primary-foreground font-bold text-lg select-none">
        J
      </div>

      <nav className="flex flex-1 flex-col gap-1">
        {NAV.map(({ href, icon: Icon, label }) => {
          const active =
            href === '/dashboard'
              ? pathname === '/dashboard'
              : pathname.startsWith(href)
          return (
            <Link
              key={href}
              href={href}
              title={label}
              className={cn(
                'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                active
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
              )}
            >
              <Icon size={20} />
            </Link>
          )
        })}
      </nav>
    </aside>
  )
}
