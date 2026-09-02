import type { TaskPriority, TaskStatus } from '../types';

/**
 * Mirrors `TransitionTaskStatusAction::ALLOWED_TRANSITIONS` exactly (backend
 * source of truth — see TransitionTaskStatusAction.php). The frontend must
 * only ever offer a transition the backend will actually accept; `done ->
 * in_progress` is the approved V1 "reopen" (architecture report §11), and
 * `cancelled` is terminal (no transitions out).
 */
const ALLOWED_TRANSITIONS: Record<TaskStatus, TaskStatus[]> = {
  todo: ['in_progress', 'cancelled'],
  in_progress: ['done', 'cancelled'],
  done: ['in_progress'],
  cancelled: [],
};

export function allowedTaskStatusTransitions(current: TaskStatus): TaskStatus[] {
  return ALLOWED_TRANSITIONS[current];
}

/** Badge tone per status — a shared status-color convention, not a per-page choice. */
export const TASK_STATUS_TONE: Record<TaskStatus, 'neutral' | 'info' | 'success' | 'muted'> = {
  todo: 'neutral',
  in_progress: 'info',
  done: 'success',
  cancelled: 'muted',
};

export const TASK_PRIORITY_TONE: Record<TaskPriority, 'muted' | 'neutral' | 'warning' | 'danger'> = {
  low: 'muted',
  normal: 'neutral',
  high: 'warning',
  urgent: 'danger',
};

export const TASK_PRIORITY_ORDER: TaskPriority[] = ['low', 'normal', 'high', 'urgent'];
export const TASK_STATUS_ORDER: TaskStatus[] = ['todo', 'in_progress', 'done', 'cancelled'];
