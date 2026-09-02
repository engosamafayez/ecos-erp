import { useMemo, useState } from 'react';
import axios from 'axios';
import { Archive, Lock, LockOpen, Plus, RotateCcw, UserMinus, UserCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  ConfirmDialog,
  EmptyState,
  EntityTable,
  ErrorState,
  Pagination,
  PageHeader,
  SearchInput,
} from '@/components/crud';
import type { ActionMenuItem, ColumnDef } from '@/components/crud/types';
import { ActionMenu } from '@/components/crud/action-menu';
import { Checkbox } from '@/components/ui/checkbox';
import { Can, usePermission } from '@/features/authorization';
import { useUsersQuery, useUserTransition } from '@/features/iam-admin/hooks/use-users';
import type { LifecycleAction, UserSummary } from '@/features/iam-admin/types/user';

import { UserCreateDrawer } from './user-create-drawer';
import { UserDetailDrawer } from './user-detail-drawer';
import { UserStatusBadge } from './user-status-badge';

/**
 * §6: which lifecycle actions are even offered depends on the user's CURRENT canonical
 * state — this is convenience only, the backend (UserLifecycleService's transition map)
 * remains authoritative and will reject an invalid transition regardless of what this
 * table chooses to show.
 */
function availableActions(status: UserSummary['status']): LifecycleAction[] {
  switch (status) {
    case 'active':
      return ['deactivate', 'suspend', 'lock', 'archive'];
    case 'inactive':
    case 'suspended':
      return ['activate', 'lock', 'archive'];
    case 'locked':
      return ['unlock', 'archive'];
    case 'archived':
    case 'deleted':
      return ['restore'];
    default:
      return [];
  }
}

const ACTION_ICON: Record<LifecycleAction, ActionMenuItem['icon']> = {
  activate: UserCheck,
  suspend: UserMinus,
  deactivate: UserMinus,
  lock: Lock,
  unlock: LockOpen,
  archive: Archive,
  restore: RotateCcw,
};

export function UsersTab() {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const { can } = usePermission();

  const [search, setSearch] = useState('');
  const [includeArchived, setIncludeArchived] = useState(false);
  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(false);
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null);
  const [pendingAction, setPendingAction] = useState<{ id: number; action: LifecycleAction } | null>(null);

  const query = useUsersQuery({ q: search || undefined, page, include_archived: includeArchived, per_page: 25 });
  const users = query.data?.data ?? [];
  const meta = query.data?.meta;

  // Static per-action labels, resolved via literal selector calls (not a dynamic key) so a
  // missing translation is still a compile error, matching this codebase's i18n convention.
  const ACTION_LABEL: Record<LifecycleAction, string> = {
    activate: t(($) => $.users.lifecycle.activate),
    suspend: t(($) => $.users.lifecycle.suspend),
    deactivate: t(($) => $.users.lifecycle.deactivate),
    lock: t(($) => $.users.lifecycle.lock),
    unlock: t(($) => $.users.lifecycle.unlock),
    archive: t(($) => $.users.lifecycle.archive),
    restore: t(($) => $.users.lifecycle.restore),
  };

  const columns = useMemo<ColumnDef<UserSummary>[]>(
    () => [
      {
        key: 'name',
        header: t(($) => $.users.columns.identity),
        cell: (row) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.display_name}</span>
            <span className="text-muted-foreground text-xs">{row.email}</span>
          </div>
        ),
        sortable: false,
      },
      {
        key: 'employee_number',
        header: t(($) => $.users.columns.employeeNumber),
        cell: (row) => row.employee_number ?? '—',
      },
      {
        key: 'status',
        header: t(($) => $.users.columns.status),
        cell: (row) => <UserStatusBadge status={row.status} label={row.status_label} />,
      },
      {
        key: 'last_activity',
        header: t(($) => $.users.columns.lastActivity),
        cell: (row) => (row.last_activity_at ? new Date(row.last_activity_at).toLocaleString() : '—'),
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={t(($) => $.users.title)}
        subtitle={t(($) => $.users.subtitle)}
        actions={
          <Can permission="iam.users.create">
            <button
              type="button"
              onClick={() => setCreateOpen(true)}
              className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors"
            >
              <Plus className="size-4" />
              {t(($) => $.users.create.trigger)}
            </button>
          </Can>
        }
      />

      <div className="flex flex-wrap items-center gap-4">
        <SearchInput
          onChange={(value) => {
            setSearch(value);
            setPage(1);
          }}
          placeholder={t(($) => $.users.searchPlaceholder)}
        />
        {/* D8: archived (and deleted) excluded by default — this is the explicit opt-in filter. */}
        <label className="flex items-center gap-2 text-sm">
          <Checkbox
            checked={includeArchived}
            onCheckedChange={(checked) => {
              setIncludeArchived(checked === true);
              setPage(1);
            }}
          />
          {t(($) => $.users.includeArchived)}
        </label>
      </div>

      {/* §25: LOADING/EMPTY/ERROR/LOADED are distinguished by EntityTable itself; a failed
         read never renders an empty table as if the request had actually succeeded. */}
      <EntityTable
        columns={columns}
        data={users}
        getRowId={(row) => String(row.id)}
        isLoading={query.isLoading}
        isError={query.isError}
        errorState={<ErrorState description={query.error instanceof Error ? query.error.message : undefined} />}
        emptyState={<EmptyState title={t(($) => $.users.empty.title)} description={t(($) => $.users.empty.description)} />}
        rowActions={(row) => {
          const actions: ActionMenuItem[] = [
            {
              key: 'view',
              label: tCommon(($) => $.actions.view),
              onSelect: () => setSelectedUserId(row.id),
            },
            ...availableActions(row.status)
              .filter((action) => can(`iam.users.${action}`))
              .map<ActionMenuItem>((action) => ({
                key: action,
                label: ACTION_LABEL[action],
                icon: ACTION_ICON[action],
                variant: action === 'archive' ? 'destructive' : 'default',
                onSelect: () => setPendingAction({ id: row.id, action }),
              })),
          ];
          return <ActionMenu items={actions} />;
        }}
      />

      {meta ? (
        <Pagination
          meta={{ page: meta.page, perPage: meta.per_page, total: meta.total, lastPage: Math.max(1, Math.ceil(meta.total / meta.per_page)) }}
          onPageChange={setPage}
        />
      ) : null}

      <UserCreateDrawer open={createOpen} onOpenChange={setCreateOpen} />
      <UserDetailDrawer
        userId={selectedUserId}
        open={selectedUserId !== null}
        onOpenChange={(open) => !open && setSelectedUserId(null)}
      />
      {pendingAction ? (
        <LifecycleConfirmDialog
          userId={pendingAction.id}
          action={pendingAction.action}
          onClose={() => setPendingAction(null)}
        />
      ) : null}
    </div>
  );
}

/**
 * §6: "never imply password reset / unlock / reactivate — those are distinct operations."
 * This dialog does exactly one thing: call the one lifecycle endpoint the actor selected.
 * Named per-action confirmation copy makes clear which single transition is about to happen.
 */
function LifecycleConfirmDialog({
  userId,
  action,
  onClose,
}: {
  userId: number;
  action: LifecycleAction;
  onClose: () => void;
}) {
  const { t } = useTranslation('iam-admin');
  const transition = useUserTransition(userId);
  const [error, setError] = useState<string | null>(null);

  // §24: 409 (invalid transition / state conflict) is exactly the case this dialog most needs
  // to surface clearly — the backend's InvalidUserTransitionException message is shown verbatim
  // rather than swallowed, since it names precisely why the transition isn't currently valid.
  const description = error ?? undefined;

  return (
    <ConfirmDialog
      open
      onOpenChange={(open) => !open && onClose()}
      title={t(($) => $.users.lifecycleConfirm[action].title)}
      description={description ?? t(($) => $.users.lifecycleConfirm[action].description)}
      confirmLabel={t(($) => $.users.lifecycle[action])}
      variant={action === 'archive' ? 'destructive' : 'default'}
      loading={transition.isPending}
      onConfirm={() => {
        setError(null);
        transition.mutate(
          { action },
          {
            onSuccess: onClose,
            onError: (err) => {
              setError(
                axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
                  ? err.response.data.message
                  : t(($) => $.users.lifecycleConfirm.genericError),
              );
            },
          },
        );
      }}
    />
  );
}
