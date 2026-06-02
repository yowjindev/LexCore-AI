'use client'

import { useState } from 'react'

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ShieldAlert, CheckCircle, Bot, FileText } from 'lucide-react'
import { useAuthStore } from '@/stores/authStore'
import { SeverityBadge } from '@/components/compliance/SeverityBadge'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { EmptyState } from '@/components/shared/EmptyState'
import { ErrorState } from '@/components/shared/ErrorState'
import { listFlags, resolveFlag } from '@/lib/api/compliance'
import { parseApiError } from '@/lib/errors'
import type { ComplianceFlag } from '@/types'

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

type FlagGroup = {
  documentId: string
  title: string
  originalFilename: string
  flags: ComplianceFlag[]
}

export default function CompliancePage() {
  const queryClient = useQueryClient()
  const user = useAuthStore((s) => s.user)
  const canResolve = user?.roles.includes('admin') || user?.roles.includes('manager') || user?.roles.includes('superadmin')

  const { data, isPending, isError } = useQuery({
    queryKey: ['compliance', 'flags'],
    queryFn:  () => listFlags(),
  })

  const [resolveError, setResolveError] = useState('')
  const resolve = useMutation({
    mutationFn: (id: string) => resolveFlag(id),
    onSuccess:  () => {
      setResolveError('')
      queryClient.invalidateQueries({ queryKey: ['compliance', 'flags'] })
    },
    onError: (err) => setResolveError(parseApiError(err)),
  })

  const allFlags: ComplianceFlag[] = data?.data ?? []
  const openCount = allFlags.filter((f) => !f.is_resolved).length
  const groupedFlags = allFlags.reduce<FlagGroup[]>((groups, flag) => {
    const documentId = flag.document_id ?? 'unknown'
    const title = flag.document?.title || flag.document?.original_filename || 'Unknown document'
    const originalFilename = flag.document?.original_filename || title
    const existing = groups.find((group) => group.documentId === documentId)

    if (existing) {
      existing.flags.push(flag)
      return groups
    }

    groups.push({
      documentId,
      title,
      originalFilename,
      flags: [flag],
    })

    return groups
  }, [])

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-lg font-semibold text-foreground">Compliance Flags</h2>
          <p className="text-sm text-muted-foreground mt-1">
            {data ? `${openCount} open · ${data.meta.total} total` : ' '}
          </p>
          <p className="text-xs text-muted-foreground mt-1">Grouped by document for easier review.</p>
        </div>
      </div>

      {isPending && (
        <div className="flex justify-center py-16">
          <LoadingSpinner />
        </div>
      )}

      {isError && (
        <ErrorState
          title="Failed to load compliance flags."
          description="Check your connection and refresh the page."
        />
      )}

      {!isPending && !isError && allFlags.length === 0 && (
        <EmptyState
          icon={ShieldAlert}
          title="No compliance flags"
          description="Flags will appear here once AI analysis detects compliance issues."
        />
      )}

      {resolveError && (
        <p className="text-sm text-destructive">{resolveError}</p>
      )}

      {!isPending && !isError && groupedFlags.length > 0 && (
        <div className="space-y-5">
          {groupedFlags.map((group) => (
            <section key={group.documentId} className="rounded-2xl border border-border bg-card overflow-hidden">
              <div className="flex items-center justify-between gap-4 border-b border-border px-5 py-4">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <FileText size={15} className="text-muted-foreground" />
                    <h3 className="truncate text-sm font-semibold text-foreground">{group.title}</h3>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground truncate">{group.originalFilename}</p>
                </div>
                <div className="text-xs text-muted-foreground text-right">
                  <p>{group.flags.filter((f) => !f.is_resolved).length} open</p>
                  <p>{group.flags.length} total</p>
                </div>
              </div>

              <div className="space-y-3 p-5">
                {group.flags.map((flag) => (
                  <div
                    key={flag.id}
                    className={`rounded-xl border bg-background/60 p-4 ${flag.is_resolved ? 'opacity-50' : 'border-border'}`}
                  >
                    <div className="flex items-start justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1 flex-wrap">
                          <SeverityBadge severity={flag.severity} />
                          <span className="text-xs text-muted-foreground uppercase tracking-wide">{flag.type}</span>
                          {flag.ai_generated && (
                            <span className="inline-flex items-center gap-1 text-xs text-violet-400 bg-violet-400/10 border border-violet-400/20 rounded-md px-1.5 py-0.5">
                              <Bot size={11} />
                              AI
                              {flag.confidence !== null && (
                                <span className="text-violet-300/80">{Math.round(flag.confidence * 100)}%</span>
                              )}
                            </span>
                          )}
                          {flag.is_resolved && (
                            <span className="inline-flex items-center gap-1 text-xs text-green-400">
                              <CheckCircle size={12} />
                              Resolved
                            </span>
                          )}
                        </div>
                        <p className="text-sm font-medium text-foreground">{flag.title}</p>
                        <p className="text-sm text-muted-foreground mt-1 leading-relaxed">{flag.description}</p>
                        {flag.explanation && !flag.is_resolved && (
                          <p className="text-xs text-muted-foreground/70 mt-1 italic leading-relaxed">{flag.explanation}</p>
                        )}
                        {flag.due_date && (
                          <p className="text-xs text-muted-foreground mt-2">Due: {formatDate(flag.due_date)}</p>
                        )}
                      </div>

                      {canResolve && !flag.is_resolved && (
                        <button
                          onClick={() => { if (resolve.isPending) return; resolve.mutate(flag.id) }}
                          disabled={resolve.isPending}
                          className="shrink-0 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-accent transition-colors disabled:opacity-50"
                        >
                          {resolve.isPending && resolve.variables === flag.id ? 'Resolving…' : 'Resolve'}
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
