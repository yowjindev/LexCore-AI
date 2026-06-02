'use client'

import { useAuthStore } from '@/stores/authStore'
import { User, Mail, Shield, Key, Lock } from 'lucide-react'

const ROLE_LABELS: Record<string, string> = {
  admin:      'Administrator',
  manager:    'Manager',
  staff:      'Staff',
  superadmin: 'Platform Superadmin',
}

const PERMISSION_LABELS: Record<string, string> = {
  'documents.ai.analyze':       'Trigger AI analysis',
  'documents.ai.chat':          'Document chat (RAG)',
  'documents.search.semantic':  'Semantic search',
}

export default function SettingsPage() {
  const user = useAuthStore((s) => s.user)

  if (!user) return null

  const initials = user.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2)

  return (
    <div className="max-w-lg mx-auto space-y-5">
      <div>
        <h1 className="text-base font-semibold text-foreground">Settings</h1>
        <p className="text-sm text-muted-foreground mt-0.5">Your account and access information.</p>
      </div>

      {/* Profile card */}
      <div className="rounded-xl border bg-card p-6">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-4">Profile</h2>
        <div className="flex items-center gap-4 mb-5">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary text-lg font-bold">
            {initials}
          </div>
          <div>
            <p className="text-sm font-semibold text-foreground">{user.name}</p>
            <p className="text-xs text-muted-foreground mt-0.5">{user.email}</p>
          </div>
        </div>

        <div className="space-y-3">
          <div className="flex items-center gap-3 rounded-lg bg-muted/50 px-3 py-2.5">
            <User size={14} className="text-muted-foreground shrink-0" />
            <div className="min-w-0">
              <p className="text-xs text-muted-foreground">Full name</p>
              <p className="text-sm font-medium text-foreground truncate">{user.name}</p>
            </div>
          </div>
          <div className="flex items-center gap-3 rounded-lg bg-muted/50 px-3 py-2.5">
            <Mail size={14} className="text-muted-foreground shrink-0" />
            <div className="min-w-0">
              <p className="text-xs text-muted-foreground">Email address</p>
              <p className="text-sm font-medium text-foreground truncate">{user.email}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Roles & Permissions */}
      <div className="rounded-xl border bg-card p-6">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-4">Roles & Permissions</h2>

        <div className="mb-4">
          <p className="text-xs text-muted-foreground mb-2">Assigned roles</p>
          <div className="flex flex-wrap gap-2">
            {user.roles.map(role => (
              <span
                key={role}
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-xs font-medium text-foreground"
              >
                <Shield size={11} className="text-primary" />
                {ROLE_LABELS[role] ?? role}
              </span>
            ))}
          </div>
        </div>

        {user.permissions && user.permissions.length > 0 && (
          <div>
            <p className="text-xs text-muted-foreground mb-2">AI feature access</p>
            <div className="space-y-1.5">
              {user.permissions.map(perm => (
                <div key={perm} className="flex items-center gap-2 text-xs text-foreground">
                  <div className="h-1.5 w-1.5 rounded-full bg-green-500 shrink-0" />
                  {PERMISSION_LABELS[perm] ?? perm}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      {/* Security note */}
      <div className="rounded-xl border bg-card p-6">
        <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-4">Security</h2>
        <div className="flex items-start gap-3 rounded-lg bg-muted/50 px-3 py-3">
          <Lock size={14} className="text-muted-foreground shrink-0 mt-0.5" />
          <div>
            <p className="text-xs font-medium text-foreground">Password management</p>
            <p className="text-xs text-muted-foreground mt-0.5">
              To change your password, contact your organisation administrator. Self-service password change will be available in a future update.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
