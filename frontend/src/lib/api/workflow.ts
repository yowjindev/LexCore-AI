import api from './client'
import type { ApiResponse, WorkflowTask, DocumentReview } from '@/types'

export function getTasks(params?: { status?: string; assigned_to?: string }) {
  return api
    .get<ApiResponse<WorkflowTask[]>>('/api/v1/tasks', { params })
    .then((r) => r.data)
}

export function updateTask(id: string, action: 'complete' | 'cancel' | 'assign', assignedTo?: string) {
  return api
    .patch<ApiResponse<WorkflowTask>>(`/api/v1/tasks/${id}`, {
      action,
      ...(assignedTo ? { assigned_to: assignedTo } : {}),
    })
    .then((r) => r.data.data)
}

export function getReview(documentId: string) {
  return api
    .get<ApiResponse<DocumentReview | null>>(`/api/v1/documents/${documentId}/review`)
    .then((r) => r.data.data)
}

export function startReview(documentId: string, templateId?: string) {
  return api
    .post<ApiResponse<DocumentReview>>(`/api/v1/documents/${documentId}/review`, {
      ...(templateId ? { template_id: templateId } : {}),
    })
    .then((r) => r.data.data)
}

export function advanceStage(reviewId: string, decision: 'approved' | 'rejected', comment?: string) {
  return api
    .post<ApiResponse<DocumentReview>>(`/api/v1/reviews/${reviewId}/advance`, {
      decision,
      ...(comment ? { comment } : {}),
    })
    .then((r) => r.data.data)
}
