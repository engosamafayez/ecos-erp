import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  AttachedToType,
  OperationalContextLink,
  OperationalContextType,
  Task,
  TaskAttachment,
  TaskComment,
  TaskFilters,
  TaskPriority,
  TaskStatus,
} from '../types';

export async function listTasks(filters: TaskFilters = {}): Promise<Task[]> {
  const { data } = await api.get<ApiResponse<Task[]>>('/collaboration/tasks', {
    params: {
      scope: filters.scope,
      status: filters.status,
      priority: filters.priority,
      overdue: filters.overdue ? 1 : undefined,
      team_id: filters.team_id,
    },
  });
  return data.data;
}

export async function getTask(id: string): Promise<Task> {
  const { data } = await api.get<ApiResponse<Task>>(`/collaboration/tasks/${id}`);
  return data.data;
}

export interface CreateTaskPayload {
  title: string;
  description?: string | null;
  assigneeUserId?: number | null;
  priority?: TaskPriority;
  dueAt?: string | null;
  teamId?: string | null;
  sourceMessageId?: string | null;
}

export async function createTask(payload: CreateTaskPayload): Promise<Task> {
  const { data } = await api.post<ApiResponse<Task>>('/collaboration/tasks', {
    title: payload.title,
    description: payload.description ?? null,
    assignee_user_id: payload.assigneeUserId ?? null,
    priority: payload.priority ?? 'normal',
    due_at: payload.dueAt ?? null,
    team_id: payload.teamId ?? null,
    source_message_id: payload.sourceMessageId ?? null,
  });
  return data.data;
}

export async function updateTask(
  id: string,
  changes: Partial<{ title: string; description: string | null; priority: TaskPriority; due_at: string | null }>,
): Promise<Task> {
  const { data } = await api.patch<ApiResponse<Task>>(`/collaboration/tasks/${id}`, changes);
  return data.data;
}

export async function reassignTask(id: string, assigneeUserId: number): Promise<Task> {
  const { data } = await api.patch<ApiResponse<Task>>(`/collaboration/tasks/${id}/assignee`, {
    assignee_user_id: assigneeUserId,
  });
  return data.data;
}

export async function transitionTaskStatus(id: string, status: TaskStatus): Promise<Task> {
  const { data } = await api.patch<ApiResponse<Task>>(`/collaboration/tasks/${id}/status`, { status });
  return data.data;
}

export async function listTaskComments(taskId: string): Promise<TaskComment[]> {
  const { data } = await api.get<ApiResponse<TaskComment[]>>(`/collaboration/tasks/${taskId}/comments`);
  return data.data;
}

export async function addTaskComment(taskId: string, body: string): Promise<TaskComment> {
  const { data } = await api.post<ApiResponse<TaskComment>>(`/collaboration/tasks/${taskId}/comments`, { body });
  return data.data;
}

export async function listTaskAttachments(taskId: string): Promise<TaskAttachment[]> {
  const { data } = await api.get<ApiResponse<TaskAttachment[]>>(`/collaboration/tasks/${taskId}/attachments`);
  return data.data;
}

export async function addTaskAttachment(taskId: string, file: File): Promise<TaskAttachment> {
  const form = new FormData();
  form.append('file', file);
  const { data } = await api.post<ApiResponse<TaskAttachment>>(`/collaboration/tasks/${taskId}/attachments`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data.data;
}

export async function fetchTaskAttachmentBlob(taskId: string, documentId: string): Promise<Blob> {
  const { data } = await api.get(`/collaboration/tasks/${taskId}/attachments/${documentId}`, {
    responseType: 'blob',
  });
  return data;
}

export async function listTaskContextLinks(taskId: string): Promise<OperationalContextLink[]> {
  const { data } = await api.get<ApiResponse<OperationalContextLink[]>>(`/collaboration/tasks/${taskId}/context-links`);
  return data.data;
}

export async function attachOperationalContext(params: {
  attachedToType: AttachedToType;
  attachedToId: string;
  contextType: OperationalContextType;
  contextId: string;
}): Promise<OperationalContextLink> {
  const { data } = await api.post<ApiResponse<OperationalContextLink>>('/collaboration/context-links', {
    attached_to_type: params.attachedToType,
    attached_to_id: params.attachedToId,
    context_type: params.contextType,
    context_id: params.contextId,
  });
  return data.data;
}

export async function searchTasks(query: string, limit = 20): Promise<Task[]> {
  const { data } = await api.get<ApiResponse<Task[]>>('/collaboration/search/tasks', { params: { q: query, limit } });
  return data.data;
}
