import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useAddTaskAssignee, useRemoveTaskAssignee } from '../hooks/use-tasks';
import type { Task } from '../types';
import { UserPicker } from './user-picker';

/**
 * Additional assignees, ALONGSIDE (never replacing) the primary assignee
 * shown in the row above this panel — see AddTaskAssigneeAction and
 * TaskPolicy::manageAssignees. Mirrors TaskFollowersPanel's exact UX: self
 * add/remove is always available once you can already work on the task;
 * adding/removing someone ELSE is creator-only.
 */
export function TaskAssigneesPanel({ task }: { task: Task }) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const add = useAddTaskAssignee(task.id);
  const remove = useRemoveTaskAssignee(task.id);

  const assignees = task.additional_assignees ?? [];
  const isAlreadyAssigned = currentUserId === task.assignee_user_id || assignees.some((a) => a.id === currentUserId);
  const isCreator = currentUserId === task.creator_user_id;

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {assignees.length === 0 ? (
          <span className="text-xs text-muted-foreground">{t(($) => $.tasks.assignees.none)}</span>
        ) : (
          assignees.map((assignee) => (
            <span key={assignee.id} className="flex items-center gap-1.5 rounded-full border py-0.5 ps-0.5 pe-2 text-xs">
              <Avatar className="size-5">
                <AvatarFallback className="text-[10px]">{getInitials(assignee.name ?? '?')}</AvatarFallback>
              </Avatar>
              {assignee.id === currentUserId ? t(($) => $.tasks.assignees.you) : assignee.name}
              {assignee.job_title ? <span className="text-muted-foreground">· {assignee.job_title}</span> : null}
              {isCreator || assignee.id === currentUserId ? (
                <button
                  type="button"
                  aria-label={t(($) => $.tasks.assignees.remove)}
                  className="text-muted-foreground hover:text-destructive"
                  onClick={() =>
                    remove.mutate(assignee.id, { onError: () => toast.error(t(($) => $.errors.generic)) })
                  }
                >
                  <X className="size-3" />
                </button>
              ) : null}
            </span>
          ))
        )}
      </div>

      <div className="flex items-center gap-2">
        {!isAlreadyAssigned ? (
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs"
            onClick={() =>
              currentUserId && add.mutate(currentUserId, { onError: () => toast.error(t(($) => $.errors.generic)) })
            }
          >
            {t(($) => $.tasks.assignees.assignMe)}
          </Button>
        ) : null}

        {isCreator ? (
          <div className="w-48">
            <UserPicker
              value={null}
              onChange={(user) => {
                if (!user) return;
                add.mutate(user.id, { onError: () => toast.error(t(($) => $.errors.generic)) });
              }}
              excludeIds={[task.assignee_user_id, ...assignees.map((a) => a.id)]}
              placeholder={t(($) => $.tasks.assignees.add)}
              className="h-7 text-xs"
            />
          </div>
        ) : null}
      </div>
    </div>
  );
}
