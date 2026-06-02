'use client'

import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckSquare, Clock, AlertTriangle, XCircle, CheckCircle2, X } from 'lucide-react'
import { useAuthStore } from '@/stores/authStore'
import { LoadingSpinner } from '@/components/shared/LoadingSpinner'
import { parseApiError } from '@/lib/errors'
import { getTasks, updateTask } from '@/lib/api/workflow'
import type { WorkflowTask } from '@/types'

const PRIORITY_CONFIG = {
  urgent: { label: 'Urgent',  color: 'bg-red-500/10 text-red-600 border-red-200' },
  high:   { label: 'High',    color: 'bg-orange-500/10 text-orange-600 border-orange-200' },
  medium: { label: 'Medium',  color: 'bg-yellow-500/10 text-yellow-600 border-yellow-200' },
  low:    { label: 'Low',     color: 'bg-muted text-muted-foreground border-border' },
}

const STATUS_TABS = [
  { value: '',           label: 'All' },
  { value: 'open',       label: 'Open' },
  { value: 'in_progress',label: 'In Progress' },
  { value: 'completed',  label: 'Completed' },
]

function PriorityBadge({ priority }: { priority: WorkflowTask['priority'] }) {
  const cfg = PRIORITY_CONFIG[priority] ?? PRIORITY_CONFIG.medium
  return (
    <span className={`inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium ${cfg.color}`}>
      {cfg.label}
    </span>
  )
}

function TaskCard({ task, onAction, isPending }: {
  task: WorkflowTask
  onAction: (id: string, action: 'complete' | 'cancel') => void
  isPending: boolean
}) {
  const isOpen = task.status === 'open' || task.status === 'in_progress'
  return (
    <div className={`rounded-xl border bg-card p-4 space-y-2 ${task.status === 'completed' ? 'opacity-60' : ''}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium text-foreground truncate">{task.title}</p>
          {task.description && (
            <p className="text-xs text-muted-foreground mt-0.5 line-clamp-2">{task.description}</p>
          )}
        </div>
        <PriorityBadge priority={task.priority} />
      </div>

      <div className="flex items-center justify-between gap-2 pt-1">
        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
          {task.status === 'completed' ? (
            <><CheckCircle2 size={12} className="text-green-500" /> Completed</>
          ) : task.status === 'cancelled' ? (
            <><XCircle size={12} className="text-destructive" /> Cancelled</>
          ) : (
            <><Clock size={12} /> {task.status === 'in_progress' ? 'In progress' : 'Open'}</>
          )}
        </div>
        {isOpen && (
          <div className="flex gap-1.5">
            <button
              onClick={() => onAction(task.id, 'complete')}
              disabled={isPending}
              className="inline-flex items-center gap-1 rounded-md bg-green-500/10 px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-500/20 transition-colors disabled:opacity-50"
            >
              <CheckSquare size={11} /> Complete
            </button>
            <button
              onClick={() => onAction(task.id, 'cancel')}
              disabled={isPending}
              className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-accent transition-colors disabled:opacity-50"
            >
              <X size={11} /> Cancel
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

export default function TasksPage() {
  const queryClient        = useQueryClient()
  const [status, setStatus] = useState('')
  const [actionError, setActionError] = useState('')

  const { data, isPending } = useQuery({
    queryKey: ['tasks', status],
    queryFn:  () => getTasks(status ? { status } : undefined),
  })

  const action = useMutation({
    mutationFn: ({ id, act }: { id: string; act: 'complete' | 'cancel' }) =>
      updateTask(id, act),
    onSuccess: () => {
      setActionError('')
      queryClient.invalidateQueries({ queryKey: ['tasks'] })
    },
    onError: (err) => setActionError(parseApiError(err)),
  })

  const tasks: WorkflowTask[] = data?.data ?? []
  const openCount = tasks.filter(t => t.status === 'open' || t.status === 'in_progress').length

  return (
    <div className="max-w-2xl mx-auto space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-base font-semibold text-foreground">Tasks</h1>
          <p className="text-xs text-muted-foreground mt-0.5">
            {openCount} open item{openCount !== 1 ? 's' : ''} requiring action
          </p>
        </div>
        <div className="flex items-center gap-1 rounded-lg border border-border bg-muted/50 p-1">
          {STATUS_TABS.map(tab => (
            <button
              key={tab.value}
              onClick={() => setStatus(tab.value)}
              className={`rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                status === tab.value
                  ? 'bg-card text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {actionError && <p className="text-xs text-destructive">{actionError}</p>}

      {isPending ? (
        <div className="flex justify-center py-10"><LoadingSpinner /></div>
      ) : tasks.length === 0 ? (
        <div className="rounded-xl border bg-card p-10 text-center">
          <CheckSquare size={28} className="mx-auto text-muted-foreground mb-3" />
          <p className="text-sm font-medium text-foreground">No tasks</p>
          <p className="text-xs text-muted-foreground mt-1">
            {status ? `No ${status.replace('_', ' ')} tasks.` : 'All clear — no tasks assigned.'}
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {tasks.map(task => (
            <TaskCard
              key={task.id}
              task={task}
              onAction={(id, act) => action.mutate({ id, act })}
              isPending={action.isPending}
            />
          ))}
        </div>
      )}
    </div>
  )
}
