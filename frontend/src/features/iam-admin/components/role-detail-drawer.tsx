import { useTranslation } from 'react-i18next';

import { EntityDrawer, ErrorState, LoadingState } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { useRoleQuery } from '@/features/iam-admin/hooks/use-roles';

/**
 * §10/§11: role detail is read-only over compiled permissions. Per RoleController's own
 * architecture note, a template-linked role points back to "manage this in Role Templates"
 * rather than offering an edit affordance here — editing a role directly would bypass the
 * template compiler's fail-closed validation (§11's own "do not implement raw table CRUD").
 */
export function RoleDetailDrawer({
  roleId,
  open,
  onOpenChange,
  onOpenTemplate,
}: {
  roleId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onOpenTemplate: (templateKey: string) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const query = useRoleQuery(roleId);

  return (
    <EntityDrawer open={open} onOpenChange={onOpenChange} title={query.data?.name ?? t(($) => $.roles.detail.title)}>
      {query.isLoading ? (
        <LoadingState />
      ) : query.isError ? (
        <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />
      ) : query.data ? (
        <div className="flex flex-col gap-4">
          <div className="flex items-center gap-2">
            {query.data.is_system ? (
              <Badge variant="outline">{t(($) => $.roles.systemBadge)}</Badge>
            ) : (
              <Badge variant="secondary">{t(($) => $.roles.customBadge)}</Badge>
            )}
            {query.data.user_count !== null ? (
              <span className="text-muted-foreground text-xs">
                {t(($) => $.roles.detail.userCount, { count: query.data.user_count })}
              </span>
            ) : null}
          </div>

          {query.data.template ? (
            <button
              type="button"
              onClick={() => query.data?.template && onOpenTemplate(query.data.template.key)}
              className="text-primary rounded-md border border-dashed px-3 py-2 text-start text-sm hover:bg-muted/50"
            >
              {t(($) => $.roles.detail.managedByTemplate, { name: query.data.template.name })}
            </button>
          ) : null}

          <div>
            <h3 className="mb-2 text-sm font-semibold">{t(($) => $.roles.detail.permissions)}</h3>
            {query.data.permissions.length === 0 ? (
              <p className="text-muted-foreground text-sm">{t(($) => $.roles.detail.noPermissions)}</p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {query.data.permissions.map((permission) => (
                  <Badge key={permission} variant="outline" className="font-mono text-xs">
                    {permission}
                  </Badge>
                ))}
              </div>
            )}
          </div>
        </div>
      ) : null}
    </EntityDrawer>
  );
}
