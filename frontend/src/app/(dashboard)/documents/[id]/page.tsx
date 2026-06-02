'use client'

import { useParams } from 'next/navigation'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { FileText, Bot, ShieldAlert, CheckCircle, ArrowLeft, RefreshCw, MessageSquare, ClipboardList } from 'lucide-react'
import Link from 'next/link'
import { useAuthStore } from '@/stores/authStore'
import { StatusBadge } from '@/components/documents/StatusBadge'
import { SeverityBadge } from '@/components/compliance/SeverityBadge'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { ErrorState } from '@/components/shared/ErrorState'
import { getDocument, retryDocument } from '@/lib/api/documents'
import { listFlags, resolveFlag } from '@/lib/api/compliance'
import type { ComplianceFlag } from '@/types'

function formatDate(date: string | null | undefined): string {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function isProcessingStatus(status: string): boolean {
  return ['pending', 'processing', 'ocr_processing', 'ocr_completed', 'ai_processing'].includes(status)
}

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const queryClient = useQueryClient()
  const user = useAuthStore((s) => s.user)
  const canResolve = user?.roles.includes('admin') || user?.roles.includes('manager') || user?.roles.includes('superadmin')

  const { data: document, isPending: docPending, isError: docError } = useQuery({
    queryKey: ['documents', id],
    queryFn:  () => getDocument(id),
    refetchInterval: (query) => {
      const document = query.state.data
      return document && isProcessingStatus(document.status) ? 3000 : false
    },
    refetchOnWindowFocus: true,
  })

  const { data: flagsData, isPending: flagsPending } = useQuery({
    queryKey: ['compliance', 'flags', id],
    queryFn:  () => listFlags(1, id),
    enabled:  !!id,
    refetchInterval: () => (document && (isProcessingStatus(document.status) || document.status === 'analyzed') ? 3000 : false),
    refetchOnWindowFocus: true,
  })

  const resolve = useMutation({
    mutationFn: (flagId: string) => resolveFlag(flagId),
    onSuccess:  () => queryClient.invalidateQueries({ queryKey: ['compliance', 'flags', id] }),
  })

  const retry = useMutation({
    mutationFn: () => retryDocument(id),
    onSuccess:  () => queryClient.invalidateQueries({ queryKey: ['documents', id] }),
  })

  const flags: ComplianceFlag[] = flagsData?.data ?? []

  if (docPending) {
    return (
      <div className="flex justify-center py-16">
        <LoadingSpinner />
      </div>
    )
  }

  if (docError || !document) {
    return (
      <ErrorState
        title="Failed to load document."
        description="This document may not exist or you don't have access."
      />
    )
  }

  const analysis = document.analysis

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <div className="flex items-center gap-3">
        <Link
          href="/documents"
          className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft size={14} />
          Documents
        </Link>
      </div>

      {/* Document header */}
      <div className="rounded-xl border bg-card p-6">
        <div className="flex items-start gap-4">
          <div className="shrink-0 rounded-lg bg-muted p-3">
            <FileText size={20} className="text-muted-foreground" />
          </div>
          <div className="flex-1 min-w-0">
            <h1 className="text-base font-semibold text-foreground truncate">{document.title}</h1>
            <p className="text-sm text-muted-foreground mt-0.5">{document.original_filename}</p>
            <div className="flex items-center gap-3 mt-2">
              <StatusBadge status={document.status} />
              <span className="text-xs text-muted-foreground">{formatBytes(document.file_size)}</span>
              <span className="text-xs text-muted-foreground">Uploaded {formatDate(document.created_at)}</span>
            </div>
            {document.status === 'analyzed' && (
              <div className="mt-3 flex flex-wrap gap-2">
                <Link
                  href={`/documents/${document.id}/chat`}
                  className="inline-flex items-center gap-1.5 rounded-lg bg-primary/10 px-3 py-1.5 text-xs font-medium text-primary hover:bg-primary/20 transition-colors"
                >
                  <MessageSquare size={13} />
                  Chat with document
                </Link>
                <Link
                  href={`/documents/${document.id}/review`}
                  className="inline-flex items-center gap-1.5 rounded-lg bg-muted px-3 py-1.5 text-xs font-medium text-foreground hover:bg-accent transition-colors"
                >
                  <ClipboardList size={13} />
                  Review workflow
                </Link>
              </div>
            )}
            {isProcessingStatus(document.status) && (
              <p className="text-xs text-muted-foreground mt-2">Checking for status updates every few seconds.</p>
            )}
          </div>
        </div>
      </div>

      {document.status === 'failed' && (
        <div className="rounded-xl border border-destructive/20 bg-destructive/10 p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <h2 className="text-sm font-semibold text-destructive">Processing failed</h2>
              {document.failure_reason && (
                <p className="mt-1 text-sm text-destructive/90">{document.failure_reason}</p>
              )}
            </div>
            <button
              onClick={() => retry.mutate()}
              disabled={retry.isPending}
              className="flex shrink-0 items-center gap-1.5 rounded-lg bg-destructive/15 px-3 py-1.5 text-xs font-medium text-destructive hover:bg-destructive/25 transition-colors disabled:opacity-50"
            >
              <RefreshCw size={12} className={retry.isPending ? 'animate-spin' : ''} />
              {retry.isPending ? 'Retrying…' : 'Retry'}
            </button>
          </div>
          {retry.isError && (
            <p className="mt-2 text-xs text-destructive">{String(retry.error)}</p>
          )}
        </div>
      )}

      {document.ocr_progress && document.ocr_progress.total_chunks > 1 && (
        <div className="rounded-xl border bg-card p-6 space-y-4">
          <div className="flex items-center justify-between gap-4">
            <div>
              <h2 className="text-sm font-semibold text-foreground">Chunk Progress</h2>
              <p className="text-xs text-muted-foreground">
                {document.ocr_progress.completed_chunks}/{document.ocr_progress.total_chunks} chunks complete
              </p>
            </div>
            <span className="text-sm font-medium text-foreground">
              {document.ocr_progress.progress_percentage.toFixed(0)}%
            </span>
          </div>
          <div className="h-2 w-full rounded-full bg-muted overflow-hidden">
            <div
              className="h-full rounded-full bg-primary transition-all"
              style={{ width: `${Math.min(100, document.ocr_progress.progress_percentage)}%` }}
            />
          </div>
          {document.ocr_chunks?.length ? (
            <div className="grid gap-2 sm:grid-cols-2">
              {document.ocr_chunks.map((chunk) => (
                <div key={chunk.id} className="rounded-lg border border-border p-3 text-xs">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium text-foreground">
                      Chunk {chunk.chunk_index + 1} ({chunk.page_start}-{chunk.page_end})
                    </span>
                    <span className="text-muted-foreground uppercase">{chunk.status}</span>
                  </div>
                  {chunk.error_message && (
                    <p className="mt-1 text-destructive/90">{chunk.error_message}</p>
                  )}
                </div>
              ))}
            </div>
          ) : null}
        </div>
      )}

      {/* Analysis panel */}
      {analysis && (
        <div className="rounded-xl border bg-card p-6 space-y-4">
          <div className="flex items-center gap-2">
            <Bot size={16} className="text-violet-400" />
            <h2 className="text-sm font-semibold text-foreground">AI Analysis</h2>
            <span className="text-xs text-muted-foreground">
              {Math.round(analysis.confidence * 100)}% confidence · {analysis.ai_model}
            </span>
          </div>

          <p className="text-sm text-muted-foreground leading-relaxed">{analysis.summary}</p>

          <div className="grid grid-cols-2 gap-4 text-xs">
            {analysis.governing_law && (
              <div>
                <p className="text-muted-foreground font-medium uppercase tracking-wide mb-0.5">Governing Law</p>
                <p className="text-foreground">{analysis.governing_law}</p>
              </div>
            )}
            {analysis.effective_date && (
              <div>
                <p className="text-muted-foreground font-medium uppercase tracking-wide mb-0.5">Effective Date</p>
                <p className="text-foreground">{formatDate(analysis.effective_date)}</p>
              </div>
            )}
            {analysis.parties.length > 0 && (
              <div className="col-span-2">
                <p className="text-muted-foreground font-medium uppercase tracking-wide mb-0.5">Parties</p>
                <p className="text-foreground">{analysis.parties.join(' · ')}</p>
              </div>
            )}
            <div>
              <p className="text-muted-foreground font-medium uppercase tracking-wide mb-0.5">Risk Score</p>
              <p className={`font-semibold ${analysis.risk_score >= 0.7 ? 'text-red-400' : analysis.risk_score >= 0.4 ? 'text-yellow-400' : 'text-green-400'}`}>
                {Math.round(analysis.risk_score * 100)} / 100
              </p>
            </div>
          </div>

          {analysis.key_points.length > 0 && (
            <div>
              <p className="text-xs text-muted-foreground font-medium uppercase tracking-wide mb-2">Key Points</p>
              <ul className="space-y-1">
                {analysis.key_points.map((point, i) => (
                  <li key={i} className="text-sm text-muted-foreground flex gap-2">
                    <span className="shrink-0 text-muted-foreground/50">·</span>
                    {point}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

      {/* Compliance flags */}
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <ShieldAlert size={16} className="text-muted-foreground" />
          <h2 className="text-sm font-semibold text-foreground">
            Compliance Flags
            {!flagsPending && (
              <span className="ml-2 text-muted-foreground font-normal">
                {flags.length === 0 ? '— none' : `(${flags.filter((f) => !f.is_resolved).length} open)`}
              </span>
            )}
          </h2>
        </div>

        {flagsPending && <LoadingSpinner />}

        {!flagsPending && flags.length === 0 && (
          <p className="text-sm text-muted-foreground py-4 text-center">No compliance flags for this document.</p>
        )}

        {!flagsPending && flags.map((flag) => (
          <div
            key={flag.id}
            className={`rounded-xl border bg-card p-4 ${flag.is_resolved ? 'opacity-50' : ''}`}
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
                  <p className="text-xs text-muted-foreground/70 mt-1 italic">{flag.explanation}</p>
                )}
              </div>

              {canResolve && !flag.is_resolved && (
                <button
                  onClick={() => { if (resolve.isPending) return; resolve.mutate(flag.id) }}
                  disabled={resolve.isPending && resolve.variables === flag.id}
                  className="shrink-0 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-accent transition-colors disabled:opacity-50"
                >
                  {resolve.isPending && resolve.variables === flag.id ? 'Resolving…' : 'Resolve'}
                </button>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
