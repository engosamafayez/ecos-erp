import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useFollowTask, useUnfollowTask } from '../hooks/use-tasks';
import type { Task } from '../types';
import { UserPicker } from './user-picker';

/** Followers/watchers — DISTINCT from the primary assignee. Self-follow/unfollow is
 *  always available; adding someone ELSE is creator-only (backend-enforced, see
 *  TaskPolicy::manageFollower) — the picker below simply reflects that in the UI. */
export function TaskFollowersPanel({ task }: { task: Task }) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const follow = useFollowTask(task.id);
  const unfollow = useUnfollowTask(task.id);

  const followers = task.followers ?? [];
  const isFollowing = currentUserId != null && followers.some((f) => f.user_id === currentUserId);
  const isCreator = currentUserId === task.creator_user_id;

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        {followers.length === 0 ? (
          <span className="text-xs text-muted-foreground">{t(($) => $.tasks.followers.none)}</span>
        ) : (
          followers.map((follower) => (
            <span key={follower.user_id} className="flex items-center gap-1.5 rounded-full border py-0.5 ps-0.5 pe-2 text-xs">
              <Avatar className="size-5">
                <AvatarFallback className="text-[10px]">{getInitials(follower.name ?? '?')}</AvatarFallback>
              </Avatar>
              {follower.user_id === currentUserId ? t(($) => $.tasks.followers.you) : follower.name}
              {isCreator || follower.user_id === currentUserId ? (
                <button
                  type="button"
                  aria-label={t(($) => $.tasks.followers.unfollow)}
                  className="text-muted-foreground hover:text-destructive"
                  onClick={() =>
                    unfollow.mutate(follower.user_id, { onError: () => toast.error(t(($) => $.errors.generic)) })
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
        {!isFollowing ? (
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs"
            onClick={() => follow.mutate(undefined, { onError: () => toast.error(t(($) => $.errors.generic)) })}
          >
            {t(($) => $.tasks.followers.follow)}
          </Button>
        ) : null}

        {isCreator ? (
          <div className="w-48">
            <UserPicker
              value={null}
              onChange={(user) => {
                if (!user) return;
                follow.mutate(user.id, { onError: () => toast.error(t(($) => $.errors.generic)) });
              }}
              excludeIds={followers.map((f) => f.user_id)}
              placeholder={t(($) => $.tasks.followers.add)}
              className="h-7 text-xs"
            />
          </div>
        ) : null}
      </div>
    </div>
  );
}
