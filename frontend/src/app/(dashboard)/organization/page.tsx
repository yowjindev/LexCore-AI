'use client'

import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, Users, UserPlus, X, Mail, User, Shield } from 'lucide-react'
import { useAuthStore } from '@/stores/authStore'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { parseApiError } from '@/lib/errors'
import { getOrganization, getMembers, inviteMember } from '@/lib/api/organization'
import type { User as UserType } from '@/types'

const ROLE_LABELS: Record<string, string> = {
  admin:      'Admin',
  manager:    'Manager',
  staff:      'Staff',
  superadmin: 'Superadmin',
}

const ROLE_COLORS: Record<string, string> = {
  admin:      'bg-blue-500/10 text-blue-600',
  manager:    'bg-violet-500/10 text-violet-600',
  staff:      'bg-muted text-muted-foreground',
  superadmin: 'bg-amber-500/10 text-amber-600',
}

function RoleBadge({ role }: { role: string }) {
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${ROLE_COLORS[role] ?? 'bg-muted text-muted-foreground'}`}>
      {ROLE_LABELS[role] ?? role}
    </span>
  )
}

function MemberRow({ member }: { member: UserType }) {
  const initials = member.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2)
  return (
    <div className="flex items-center gap-3 py-3 border-b border-border last:border-0">
      <div className="shrink-0 flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-primary text-sm font-semibold">
        {initials}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-foreground truncate">{member.name}</p>
        <p className="text-xs text-muted-foreground truncate">{member.email}</p>
      </div>
      <div className="flex gap-1.5 shrink-0">
        {member.roles.map(role => <RoleBadge key={role} role={role} />)}
      </div>
    </div>
  )
}

export default function OrganizationPage() {
  const queryClient  = useQueryClient()
  const user         = useAuthStore((s) => s.user)
  const canInvite    = user?.roles.includes('admin') || user?.roles.includes('manager') || user?.roles.includes('superadmin')

  const [modalOpen, setModalOpen] = useState(false)
  const [name, setName]           = useState('')
  const [email, setEmail]         = useState('')
  const [role, setRole]           = useState<'admin' | 'manager' | 'staff'>('staff')
  const [inviteError, setInviteError] = useState('')

  const { data: org, isPending: orgPending } = useQuery({
    queryKey: ['organization'],
    queryFn:  () => getOrganization(),
  })

  const { data: members, isPending: membersPending } = useQuery({
    queryKey: ['organization', 'members'],
    queryFn:  () => getMembers(),
  })

  const invite = useMutation({
    mutationFn: () => inviteMember(name, email, role),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['organization', 'members'] })
      setModalOpen(false)
      setName('')
      setEmail('')
      setRole('staff')
      setInviteError('')
    },
    onError: (err) => setInviteError(parseApiError(err)),
  })

  return (
    <div className="max-w-2xl mx-auto space-y-6">
      {/* Org header card */}
      <div className="rounded-xl border bg-card p-6">
        <div className="flex items-start gap-4">
          <div className="shrink-0 rounded-lg bg-primary/10 p-3">
            <Building2 size={20} className="text-primary" />
          </div>
          <div className="flex-1 min-w-0">
            {orgPending ? (
              <div className="h-5 w-40 bg-muted animate-pulse rounded" />
            ) : (
              <>
                <h1 className="text-base font-semibold text-foreground">{org?.name ?? '—'}</h1>
                <p className="text-sm text-muted-foreground mt-0.5">
                  {org?.industry && <span>{org.industry} · </span>}
                  slug: <span className="font-mono">{org?.slug}</span>
                </p>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Members section */}
      <div className="rounded-xl border bg-card">
        <div className="flex items-center justify-between px-5 py-4 border-b border-border">
          <div className="flex items-center gap-2">
            <Users size={16} className="text-muted-foreground" />
            <h2 className="text-sm font-semibold text-foreground">
              Members {members ? `(${members.length})` : ''}
            </h2>
          </div>
          {canInvite && (
            <button
              onClick={() => setModalOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <UserPlus size={13} />
              Invite member
            </button>
          )}
        </div>

        <div className="px-5">
          {membersPending ? (
            <div className="flex justify-center py-8">
              <LoadingSpinner />
            </div>
          ) : !members?.length ? (
            <p className="py-8 text-center text-sm text-muted-foreground">No members found.</p>
          ) : (
            members.map(m => <MemberRow key={m.id} member={m} />)
          )}
        </div>
      </div>

      {/* Invite modal */}
      {modalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-xl">
            <div className="flex items-center justify-between mb-5">
              <h3 className="text-sm font-semibold text-foreground">Invite new member</h3>
              <button onClick={() => { setModalOpen(false); setInviteError('') }} className="text-muted-foreground hover:text-foreground">
                <X size={16} />
              </button>
            </div>

            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-foreground mb-1">Full name</label>
                <div className="relative">
                  <User size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <input
                    type="text"
                    value={name}
                    onChange={e => setName(e.target.value)}
                    placeholder="Jane Dela Cruz"
                    className="w-full rounded-lg border border-input bg-background pl-9 pr-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-foreground mb-1">Email address</label>
                <div className="relative">
                  <Mail size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <input
                    type="email"
                    value={email}
                    onChange={e => setEmail(e.target.value)}
                    placeholder="jane@example.com"
                    className="w-full rounded-lg border border-input bg-background pl-9 pr-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                  />
                </div>
              </div>

              <div>
                <label className="block text-xs font-medium text-foreground mb-1">Role</label>
                <div className="relative">
                  <Shield size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <select
                    value={role}
                    onChange={e => setRole(e.target.value as 'admin' | 'manager' | 'staff')}
                    className="w-full appearance-none rounded-lg border border-input bg-background pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                  >
                    <option value="staff">Staff</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                  </select>
                </div>
              </div>

              {inviteError && (
                <p className="text-xs text-destructive">{inviteError}</p>
              )}

              <div className="flex gap-3 pt-1">
                <button
                  onClick={() => { setModalOpen(false); setInviteError('') }}
                  className="flex-1 rounded-lg border border-border py-2 text-sm font-medium text-foreground hover:bg-accent transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={() => invite.mutate()}
                  disabled={!name.trim() || !email.trim() || invite.isPending}
                  className="flex-1 rounded-lg bg-primary py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50"
                >
                  {invite.isPending ? 'Inviting…' : 'Send invite'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
