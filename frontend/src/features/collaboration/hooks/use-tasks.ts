import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  addTaskAttachment,
  addTaskComment,
  attachOperationalContext,
  createTask,
  type CreateTaskPayload,
  getTask,
  listTaskAttachments,
  listTaskComments,
  listTaskContextLinks,
  listTasks,
  reassignTask,
  searchTasks,
  transitionTaskStatus,
  updateTask,
} from '../services/tasks-service';
import type { AttachedToType, OperationalContextType, TaskFilters, TaskStatus } from '../types';
import { useRealtimeStatus } from './use-realtime-status';

const tasksKey = (filters: TaskFilters) => ['collaboration', 'tasks', filters] as const;
const taskKey = (id: string) => ['collaboration', 'tasks', 'detail', id] as const;
const commentsKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'comments'] as const;
const attachmentsKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'attachments'] as const;
const contextLinksKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'context-links'] as const;

export function useTasks(filters: TaskFilters = {}) {
  const realtime = useRealtimeStatus();

  return useQuery({
    queryKey: tasksKey(filters),
    queryFn: () => listTasks(filters),
    refetchInterval: realtime === 'connected' ? false : 15_000,
  });
}

export function useTask(id: string | null) {
  const realtime = useRealtimeStatus();

  return useQuery({
    queryKey: taskKey(id ?? ''),
    queryFn: () => getTask(id as string),
    enabled: !!id,
    refetchInterval: realtime === 'connected' ? false : 8_000,
  });
}

function invalidateTaskLists(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: ['collaboration', 'tasks'] });
}

export function useCreateTask() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateTaskPayload) => createTask(payload),
    onSuccess: () => invalidateTaskLists(qc),
  });
}

export function useUpdateTask(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (changes: Parameters<typeof updateTask>[1]) => updateTask(taskId, changes),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}

export function useReassignTask(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (assigneeUserId: number) => reassignTask(taskId, assigneeUserId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}

/** Callers must only offer statuses allowed from the task's current status — see TaskStatusControl. */
export function useTransitionTaskStatus(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (status: TaskStatus) => transitionTaskStatus(taskId, status),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}

export function useTaskComments(taskId: string) {
  return useQuery({
    queryKey: commentsKey(taskId),
    queryFn: () => listTaskComments(taskId),
  });
}

export function useAddTaskComment(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: string) => addTaskComment(taskId, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: commentsKey(taskId) }),
  });
}

export function useTaskAttachments(taskId: string) {
  return useQuery({
    queryKey: attachmentsKey(taskId),
    queryFn: () => listTaskAttachments(taskId),
  });
}

export function useAddTaskAttachment(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (file: File) => addTaskAttachment(taskId, file),
    onSuccess: () => qc.invalidateQueries({ queryKey: attachmentsKey(taskId) }),
  });
}

export function useTaskContextLinks(taskId: string) {
  return useQuery({
    queryKey: contextLinksKey(taskId),
    queryFn: () => listTaskContextLinks(taskId),
  });
}

export function useAttachTaskContext(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (params: { contextType: OperationalContextType; contextId: string }) =>
      attachOperationalContext({
        attachedToType: 'task' as AttachedToType,
        attachedToId: taskId,
        contextType: params.contextType,
        contextId: params.contextId,
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: contextLinksKey(taskId) }),
  });
}

export function useSearchTasks(query: string) {
  return useQuery({
    queryKey: ['collaboration', 'search', 'tasks', query],
    queryFn: () => searchTasks(query),
    enabled: query.trim().length > 0,
  });
}
