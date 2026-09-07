import { useMemo, useState } from 'react';
import { Copy, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState, EntityTable, ErrorState, PageHeader } from '@/components/crud';
import { ActionMenu } from '@/components/crud/action-menu';
import type { ActionMenuItem, ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Can } from '@/features/authorization';
import { useRoleTemplatesQuery } from '@/features/iam-admin/hooks/use-role-templates';
import type { RoleTemplateSummary } from '@/features/iam-admin/types/role-template';

import { TemplateCloneDialog } from './template-clone-dialog';
import { TemplateCreateDrawer } from './template-create-drawer';
import { TemplateDetailDrawer } from './template-detail-drawer';

export function RoleTemplatesTab({
  focusKey,
  onFocusHandled,
}: {
  /** Set when the actor arrived here via "managed by template" from the Roles tab. */
  focusKey?: string | null;
  onFocusHandled?: () => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const query = useRoleTemplatesQuery();

  const [createOpen, setCreateOpen] = useState(false);
  const [selectedKey, setSelectedKey] = useState<string | null>(focusKey ?? null);
  const [cloneTarget, setCloneTarget] = useState<RoleTemplateSummary | null>(null);

  // focusKey is a prop, not just an initial value — a later "managed by template" click while
  // this tab is already mounted must re-open the drawer too, not only on first mount.
  // Adjusted during render (React's documented pattern for this) rather than in a useEffect,
  // which would cost an extra render.
  const [prevFocusKey, setPrevFocusKey] = useState(focusKey);
  if (focusKey !== prevFocusKey) {
    setPrevFocusKey(focusKey);
    if (focusKey) {
      setSelectedKey(focusKey);
    }
  }

  const columns = useMemo<ColumnDef<RoleTemplateSummary>[]>(
    () => [
      {
        key: 'name',
        header: t(($) => $.roleTemplates.columns.name),
        cell: (row) => (
          <button type="button" onClick={() => setSelectedKey(row.key)} className="text-start hover:underline">
            <span className="block font-medium">{row.name_ar}</span>
            {row.name_ar !== row.name ? <span className="text-muted-foreground block text-xs">{row.name}</span> : null}
          </button>
        ),
      },
      {
        key: 'type',
        header: t(($) => $.roleTemplates.columns.type),
        cell: (row) =>
          row.is_system ? (
            <Badge variant="outline">{t(($) => $.roleTemplates.systemBadge)}</Badge>
          ) : (
            <Badge variant="secondary">{t(($) => $.roleTemplates.customBadge)}</Badge>
          ),
      },
      {
        key: 'category',
        header: t(($) => $.roleTemplates.columns.category),
        cell: (row) => row.category,
      },
      {
        key: 'version',
        header: t(($) => $.roleTemplates.columns.version),
        cell: (row) => `v${row.version}`,
      },
      {
        key: 'status',
        header: t(($) => $.roleTemplates.columns.status),
        cell: (row) => <Badge variant="outline">{row.status}</Badge>,
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={t(($) => $.roleTemplates.title)}
        subtitle={t(($) => $.roleTemplates.subtitle)}
        actions={
          <Can permission="iam.role-templates.create">
            <button
              type="button"
              onClick={() => setCreateOpen(true)}
              className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors"
            >
              <Plus className="size-4" />
              {t(($) => $.roleTemplates.create.trigger)}
            </button>
          </Can>
        }
      />

      <EntityTable
        columns={columns}
        data={query.data ?? []}
        getRowId={(row) => row.key}
        isLoading={query.isLoading}
        isError={query.isError}
        errorState={<ErrorState description={query.error instanceof Error ? query.error.message : undefined} />}
        emptyState={<EmptyState title={t(($) => $.roleTemplates.empty)} />}
        rowActions={(row) => {
          const actions: ActionMenuItem[] = [
            { key: 'view', label: tCommon(($) => $.actions.view), onSelect: () => setSelectedKey(row.key) },
          ];
          actions.push({
            key: 'clone',
            label: t(($) => $.roleTemplates.cloneAction),
            icon: Copy,
            onSelect: () => setCloneTarget(row),
          });
          return <ActionMenu items={actions} />;
        }}
      />

      <TemplateCreateDrawer open={createOpen} onOpenChange={setCreateOpen} />
      <TemplateDetailDrawer
        templateKey={selectedKey}
        open={selectedKey !== null}
        onOpenChange={(open) => {
          if (!open) {
            setSelectedKey(null);
            onFocusHandled?.();
          }
        }}
      />
      {cloneTarget ? (
        <TemplateCloneDialog
          sourceKey={cloneTarget.key}
          sourceName={cloneTarget.name}
          open
          onOpenChange={(open) => !open && setCloneTarget(null)}
        />
      ) : null}
    </div>
  );
}
