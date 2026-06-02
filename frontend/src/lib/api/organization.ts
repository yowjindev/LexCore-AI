import api from './client'
import type { ApiResponse, User } from '@/types'

export interface OrganizationDetail {
  id: string
  name: string
  slug: string
  industry: string | null
  plan: string | null
  created_at: string
}

export function getOrganization() {
  return api
    .get<ApiResponse<OrganizationDetail>>('/api/v1/organization')
    .then((r) => r.data.data)
}

export function getMembers() {
  return api
    .get<ApiResponse<User[]>>('/api/v1/organization/members')
    .then((r) => r.data.data)
}

export function inviteMember(name: string, email: string, role: 'admin' | 'manager' | 'staff') {
  return api
    .post<ApiResponse<User>>('/api/v1/organization/invitations', { name, email, role })
    .then((r) => r.data.data)
}
