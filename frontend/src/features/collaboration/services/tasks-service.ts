import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  AttachedToType,
  OperationalContextLink,
  OperationalContextType,
  Task,
  TaskAttachment,
  TaskBoardList,
  TaskChecklist,
  TaskChecklistItem,
  TaskComment,
  TaskFilters,
  TaskLabel,
  TaskLabelColor,
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

// ---- Board lists (organizational containers — never a second TaskStatus, see TaskBoardList) ----

export async function listTaskBoardLists(): Promise<TaskBoardList[]> {
  const { data } = await api.get<ApiResponse<TaskBoardList[]>>('/collaboration/task-lists');
  return data.data;
}

export async function createTaskBoardList(name: string): Promise<TaskBoardList> {
  const { data } = await api.post<ApiResponse<TaskBoardList>>('/collaboration/task-lists', { name });
  return data.data;
}

export async function renameTaskBoardList(id: string, name: string): Promise<TaskBoardList> {
  const { data } = await api.patch<ApiResponse<TaskBoardList>>(`/collaboration/task-lists/${id}`, { name });
  return data.data;
}

export async function reorderTaskBoardLists(listIds: string[]): Promise<void> {
  await api.patch('/collaboration/task-lists/reorder', { list_ids: listIds });
}

export async function archiveTaskBoardList(id: string): Promise<TaskBoardList> {
  const { data } = await api.patch<ApiResponse<TaskBoardList>>(`/collaboration/task-lists/${id}/archive`);
  return data.data;
}

export async function restoreTaskBoardList(id: string): Promise<TaskBoardList> {
  const { data } = await api.patch<ApiResponse<TaskBoardList>>(`/collaboration/task-lists/${id}/restore`);
  return data.data;
}

export async function moveTaskCard(taskId: string, taskListId: string, position: number): Promise<Task> {
  const { data } = await api.patch<ApiResponse<Task>>(`/collaboration/tasks/${taskId}/move`, {
    task_list_id: taskListId,
    position,
  });
  return data.data;
}

// ---- Labels ----

export async function listTaskLabels(): Promise<TaskLabel[]> {
  const { data } = await api.get<ApiResponse<TaskLabel[]>>('/collaboration/task-labels');
  return data.data;
}

export async function createTaskLabel(name: string, color: TaskLabelColor): Promise<TaskLabel> {
  const { data } = await api.post<ApiResponse<TaskLabel>>('/collaboration/task-labels', { name, color });
  return data.data;
}

export async function attachTaskLabel(taskId: string, labelId: string): Promise<Task> {
  const { data } = await api.post<ApiResponse<Task>>(`/collaboration/tasks/${taskId}/labels/${labelId}`);
  return data.data;
}

export async function detachTaskLabel(taskId: string, labelId: string): Promise<void> {
  await api.delete(`/collaboration/tasks/${taskId}/labels/${labelId}`);
}

// ---- Checklists ----

export async function listTaskChecklists(taskId: string): Promise<TaskChecklist[]> {
  const { data } = await api.get<ApiResponse<TaskChecklist[]>>(`/collaboration/tasks/${taskId}/checklists`);
  return data.data;
}

export async function createTaskChecklist(taskId: string, title: string): Promise<TaskChecklist> {
  const { data } = await api.post<ApiResponse<TaskChecklist>>(`/collaboration/tasks/${taskId}/checklists`, { title });
  return data.data;
}

export async function addTaskChecklistItem(taskId: string, checklistId: string, title: string): Promise<TaskChecklistItem> {
  const { data } = await api.post<ApiResponse<TaskChecklistItem>>(
    `/collaboration/tasks/${taskId}/checklists/${checklistId}/items`,
    { title },
  );
  return data.data;
}

export async function updateTaskChecklistItem(
  taskId: string,
  checklistId: string,
  itemId: string,
  changes: Partial<{ title: string; is_completed: boolean }>,
): Promise<TaskChecklistItem> {
  const { data } = await api.patch<ApiResponse<TaskChecklistItem>>(
    `/collaboration/tasks/${taskId}/checklists/${checklistId}/items/${itemId}`,
    changes,
  );
  return data.data;
}

export async function deleteTaskChecklistItem(taskId: string, checklistId: string, itemId: string): Promise<void> {
  await api.delete(`/collaboration/tasks/${taskId}/checklists/${checklistId}/items/${itemId}`);
}

// ---- Followers ----

export async function followTask(taskId: string, userId?: number): Promise<Task> {
  const { data } = await api.post<ApiResponse<Task>>(`/collaboration/tasks/${taskId}/followers`, {
    user_id: userId,
  });
  return data.data;
}

export async function unfollowTask(taskId: string, userId: number): Promise<void> {
  await api.delete(`/collaboration/tasks/${taskId}/followers/${userId}`);
}
