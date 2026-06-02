'use client'

import { useState } from 'react'
import { useParams } from 'next/navigation'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ClipboardList, CheckCircle, XCircle, Clock, ChevronRight } from 'lucide-react'
import Link from 'next/link'
import { useAuthStore } from '@/stores/authStore'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { getDocument } from '@/lib/api/documents'
import { getReview, startReview, advanceStage } from '@/lib/api/workflow'
import { parseApiError } from '@/lib/errors'
import type { ReviewStage } from '@/types'

const DECISION_CONFIG = {
  pending:  { icon: Clock,        color: 'text-muted-foreground', label: 'Pending' },
  approved: { icon: CheckCircle,  color: 'text-green-500',        label: 'Approved' },
  rejected: { icon: XCircle,      color: 'text-destructive',      label: 'Rejected' },
}

function StageRow({ stage, isCurrent, canDecide, onDecide, isPending }: {
  stage: ReviewStage
  isCurrent: boolean
  canDecide: boolean
  onDecide: (decision: 'approved' | 'rejected', comment?: string) => void
  isPending: boolean
}) {
  const [comment, setComment] = useState('')
  const [showForm, setShowForm] = useState(false)
  const cfg = DECISION_CONFIG[stage.decision] ?? DECISION_CONFIG.pending
  const Icon = cfg.icon

  return (
    <div className={`rounded-xl border p-4 space-y-3 ${isCurrent ? 'border-primary/30 bg-primary/5' : 'bg-card'}`}>
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2.5">
          <div className="flex h-6 w-6 items-center justify-center rounded-full border border-border bg-muted text-xs font-semibold text-muted-foreground">
            {stage.stage_index + 1}
          </div>
          <div>
            <p className="text-sm font-medium text-foreground">{stage.stage_name}</p>
            <p className="text-xs text-muted-foreground">Required: {stage.approver_role}</p>
          </div>
        </div>
        <div className="flex items-center gap-1.5">
          <Icon size={14} className={cfg.color} />
          <span className={`text-xs font-medium ${cfg.color}`}>{cfg.label}</span>
        </div>
      </div>

      {stage.comment && (
        <p className="text-xs text-muted-foreground italic border-l-2 border-border pl-3">{stage.comment}</p>
      )}

      {isCurrent && canDecide && stage.decision === 'pending' && (
        <div className="space-y-2 pt-1">
          {showForm ? (
            <>
              <textarea
                value={comment}
                onChange={e => setComment(e.target.value)}
                placeholder="Optional comment…"
                rows={2}
                className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring resize-none"
              />
              <div className="flex gap-2">
                <button
                  onClick={() => { onDecide('approved', comment || undefined); setShowForm(false) }}
                  disabled={isPending}
                  className="flex-1 rounded-lg bg-green-500 py-1.5 text-xs font-medium text-white hover:bg-green-600 transition-colors disabled:opacity-50"
                >
                  Approve
                </button>
                <button
                  onClick={() => { onDecide('rejected', comment || undefined); setShowForm(false) }}
                  disabled={isPending}
                  className="flex-1 rounded-lg bg-destructive py-1.5 text-xs font-medium text-destructive-foreground hover:bg-destructive/90 transition-colors disabled:opacity-50"
                >
                  Reject
                </button>
                <button onClick={() => setShowForm(false)} className="px-3 py-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors">
                  Cancel
                </button>
              </div>
            </>
          ) : (
            <button
              onClick={() => setShowForm(true)}
              className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <ChevronRight size={12} /> Make decision
            </button>
          )}
        </div>
      )}
    </div>
  )
}

export default function ReviewPage() {
  const { id }        = useParams<{ id: string }>()
  const queryClient   = useQueryClient()
  const user          = useAuthStore((s) => s.user)
  const [actionError, setActionError] = useState('')

  const { data: doc, isPending: docPending } = useQuery({
    queryKey: ['documents', id],
    queryFn:  () => getDocument(id),
  })

  const { data: review, isPending: reviewPending } = useQuery({
    queryKey: ['review', id],
    queryFn:  () => getReview(id),
    enabled:  !!id,
  })

  const start = useMutation({
    mutationFn: () => startReview(id),
    onSuccess: () => {
      setActionError('')
      queryClient.invalidateQueries({ queryKey: ['review', id] })
    },
    onError: (err) => setActionError(parseApiError(err)),
  })

  const advance = useMutation({
    mutationFn: ({ decision, comment }: { decision: 'approved' | 'rejected'; comment?: string }) =>
      advanceStage(review!.id, decision, comment),
    onSuccess: () => {
      setActionError('')
      queryClient.invalidateQueries({ queryKey: ['review', id] })
    },
    onError: (err) => setActionError(parseApiError(err)),
  })

  const canStartReview = user?.roles.some(r => ['admin', 'manager', 'superadmin'].includes(r))

  if (docPending || reviewPending) {
    return <div className="flex justify-center py-16"><LoadingSpinner /></div>
  }

  const statusColors: Record<string, string> = {
    in_review: 'bg-blue-500/10 text-blue-600',
    approved:  'bg-green-500/10 text-green-600',
    rejected:  'bg-red-500/10 text-red-600',
    archived:  'bg-muted text-muted-foreground',
  }

  return (
    <div className="max-w-xl mx-auto space-y-5">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Link href={`/documents/${id}`} className="text-muted-foreground hover:text-foreground transition-colors">
          <ArrowLeft size={16} />
        </Link>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-foreground truncate">
            {doc?.title ?? doc?.original_filename ?? '…'}
          </p>
          <p className="text-xs text-muted-foreground">Review Workflow</p>
        </div>
        {review && (
          <span className={`shrink-0 inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${statusColors[review.status] ?? ''}`}>
            {review.status.replace('_', ' ')}
          </span>
        )}
      </div>

      {actionError && <p className="text-sm text-destructive">{actionError}</p>}

      {/* No review yet */}
      {!review && (
        <div className="rounded-xl border bg-card p-10 text-center">
          <ClipboardList size={28} className="mx-auto text-muted-foreground mb-3" />
          <p className="text-sm font-medium text-foreground">No review started</p>
          <p className="text-xs text-muted-foreground mt-1 mb-4">
            Start a review workflow to assign approvers and track decisions.
          </p>
          {canStartReview ? (
            <button
              onClick={() => start.mutate()}
              disabled={start.isPending}
              className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50"
            >
              {start.isPending ? 'Starting…' : 'Start review'}
            </button>
          ) : (
            <p className="text-xs text-muted-foreground">Contact an admin or manager to start a review.</p>
          )}
        </div>
      )}

      {/* Stages list */}
      {review && review.stages && (
        <div className="space-y-3">
          <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
            {review.stages.length} stage{review.stages.length !== 1 ? 's' : ''}
          </p>
          {review.stages.map(stage => {
            const isCurrent = stage.stage_index === review.current_stage_index && review.status === 'in_review'
            const canDecide  = !!user?.roles.includes(stage.approver_role) || !!user?.roles.includes('superadmin')
            return (
              <StageRow
                key={stage.id}
                stage={stage}
                isCurrent={isCurrent}
                canDecide={canDecide}
                onDecide={(decision, comment) => advance.mutate({ decision, comment })}
                isPending={advance.isPending}
              />
            )
          })}
        </div>
      )}
    </div>
  )
}
