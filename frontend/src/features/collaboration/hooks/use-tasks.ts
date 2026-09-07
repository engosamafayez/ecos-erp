import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  addTaskAttachment,
  addTaskChecklistItem,
  addTaskComment,
  archiveTaskBoardList,
  attachOperationalContext,
  attachTaskLabel,
  createTask,
  createTaskBoardList,
  createTaskChecklist,
  type CreateTaskPayload,
  createTaskLabel,
  deleteTaskChecklistItem,
  detachTaskLabel,
  followTask,
  getTask,
  listTaskAttachments,
  listTaskBoardLists,
  listTaskChecklists,
  listTaskComments,
  listTaskContextLinks,
  listTaskLabels,
  listTasks,
  moveTaskCard,
  reassignTask,
  renameTaskBoardList,
  reorderTaskBoardLists,
  restoreTaskBoardList,
  searchTasks,
  transitionTaskStatus,
  unfollowTask,
  updateTask,
  updateTaskChecklistItem,
} from '../services/tasks-service';
import type { AttachedToType, OperationalContextType, TaskFilters, TaskLabelColor, TaskStatus } from '../types';
import { useRealtimeStatus } from './use-realtime-status';

const tasksKey = (filters: TaskFilters) => ['collaboration', 'tasks', filters] as const;
const taskKey = (id: string) => ['collaboration', 'tasks', 'detail', id] as const;
const commentsKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'comments'] as const;
const attachmentsKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'attachments'] as const;
const contextLinksKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'context-links'] as const;
const checklistsKey = (id: string) => ['collaboration', 'tasks', 'detail', id, 'checklists'] as const;
const boardListsKey = ['collaboration', 'task-lists'] as const;
const taskLabelsKey = ['collaboration', 'task-labels'] as const;

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

/** Card placement (organizational, brief §1) — never a TaskStatus transition.
 *  Not bound to one task id up front, since the Board's drop target can be any
 *  card in any list. */
export function useMoveTaskCard() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ taskId, taskListId, position }: { taskId: string; taskListId: string; position: number }) =>
      moveTaskCard(taskId, taskListId, position),
    onSuccess: (_, { taskId }) => {
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

// ---- Board lists ----

export function useTaskBoardLists() {
  return useQuery({ queryKey: boardListsKey, queryFn: listTaskBoardLists });
}

export function useCreateTaskBoardList() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (name: string) => createTaskBoardList(name),
    onSuccess: () => qc.invalidateQueries({ queryKey: boardListsKey }),
  });
}

export function useRenameTaskBoardList() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => renameTaskBoardList(id, name),
    onSuccess: () => qc.invalidateQueries({ queryKey: boardListsKey }),
  });
}

export function useReorderTaskBoardLists() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (listIds: string[]) => reorderTaskBoardLists(listIds),
    onSuccess: () => qc.invalidateQueries({ queryKey: boardListsKey }),
  });
}

export function useArchiveTaskBoardList() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => archiveTaskBoardList(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: boardListsKey });
      invalidateTaskLists(qc);
    },
  });
}

export function useRestoreTaskBoardList() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => restoreTaskBoardList(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: boardListsKey }),
  });
}

// ---- Labels ----

export function useTaskLabels() {
  return useQuery({ queryKey: taskLabelsKey, queryFn: listTaskLabels });
}

export function useCreateTaskLabel() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ name, color }: { name: string; color: TaskLabelColor }) => createTaskLabel(name, color),
    onSuccess: () => qc.invalidateQueries({ queryKey: taskLabelsKey }),
  });
}

export function useAttachTaskLabel(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (labelId: string) => attachTaskLabel(taskId, labelId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}

export function useDetachTaskLabel(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (labelId: string) => detachTaskLabel(taskId, labelId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}

// ---- Checklists ----

export function useTaskChecklists(taskId: string) {
  return useQuery({
    queryKey: checklistsKey(taskId),
    queryFn: () => listTaskChecklists(taskId),
  });
}

function invalidateChecklist(qc: ReturnType<typeof useQueryClient>, taskId: string) {
  qc.invalidateQueries({ queryKey: checklistsKey(taskId) });
  qc.invalidateQueries({ queryKey: taskKey(taskId) });
  invalidateTaskLists(qc);
}

export function useCreateTaskChecklist(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (title: string) => createTaskChecklist(taskId, title),
    onSuccess: () => invalidateChecklist(qc, taskId),
  });
}

export function useAddTaskChecklistItem(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ checklistId, title }: { checklistId: string; title: string }) =>
      addTaskChecklistItem(taskId, checklistId, title),
    onSuccess: () => invalidateChecklist(qc, taskId),
  });
}

export function useUpdateTaskChecklistItem(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({
      checklistId,
      itemId,
      changes,
    }: {
      checklistId: string;
      itemId: string;
      changes: Partial<{ title: string; is_completed: boolean }>;
    }) => updateTaskChecklistItem(taskId, checklistId, itemId, changes),
    onSuccess: () => invalidateChecklist(qc, taskId),
  });
}

export function useDeleteTaskChecklistItem(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ checklistId, itemId }: { checklistId: string; itemId: string }) =>
      deleteTaskChecklistItem(taskId, checklistId, itemId),
    onSuccess: () => invalidateChecklist(qc, taskId),
  });
}

// ---- Followers ----

export function useFollowTask(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (userId?: number) => followTask(taskId, userId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}

export function useUnfollowTask(taskId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) => unfollowTask(taskId, userId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: taskKey(taskId) });
      invalidateTaskLists(qc);
    },
  });
}
