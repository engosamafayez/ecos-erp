import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { PermissionBoundary, usePermission } from '@/features/authorization';
import { ROUTES } from '@/router/routes';
import { RolesPermissionsTab } from '@/features/iam-admin/components/roles-permissions-tab';
import { RoleTemplatesTab } from '@/features/iam-admin/components/role-templates-tab';
import { UsersTab } from '@/features/iam-admin/components/users-tab';

type WorkspaceTab = 'users' | 'roles' | 'templates';

const TAB_ROUTE: Record<WorkspaceTab, string> = {
  users: ROUTES.users,
  roles: ROUTES.roles,
  templates: ROUTES.roleTemplates,
};

/**
 * §1 of the task: ONE IAM Administration Workspace, three tabs. Reachable from the sidebar's
 * existing "Users" and "Roles & Permissions" nav items (previously Coming Soon placeholders,
 * see router.ts) — Role Templates has no separate nav entry (no menu explosion) and is reached
 * either by the in-page tab or by the "managed by template" pointer from a Role's detail.
 * Tab state is driven by the URL path (not a query param) so each tab is independently
 * deep-linkable and bookmarkable, matching this app's existing route-per-section convention
 * more directly than the query-param pattern Configuration OS uses for its 14 categories —
 * three tabs don't need that hub/category layering.
 */
export function IamWorkspacePage() {
  const { t } = useTranslation('iam-admin');
  const { can } = usePermission();
  const location = useLocation();
  const navigate = useNavigate();
  const [templateFocusKey, setTemplateFocusKey] = useState<string | null>(null);

  const activeTab: WorkspaceTab =
    location.pathname === ROUTES.roleTemplates
      ? 'templates'
      : location.pathname === ROUTES.roles
        ? 'roles'
        : 'users';

  const tabs: { id: WorkspaceTab; label: string; visible: boolean }[] = [
    { id: 'users', label: t(($) => $.workspace.tabs.users), visible: can('iam.users.view') },
    { id: 'roles', label: t(($) => $.workspace.tabs.roles), visible: can('iam.roles.view') },
    { id: 'templates', label: t(($) => $.workspace.tabs.templates), visible: can('iam.role-templates.view') },
  ];

  return (
    <div className="flex h-full flex-col">
      <div className="border-b px-6 pt-5">
        <h1 className="text-lg font-semibold">{t(($) => $.workspace.title)}</h1>
        <p className="text-muted-foreground mt-0.5 text-sm">{t(($) => $.workspace.subtitle)}</p>

        <div className="mt-4 flex gap-1">
          {tabs
            .filter((tab) => tab.visible)
            .map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => navigate(TAB_ROUTE[tab.id])}
                className={`rounded-t-md border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                  activeTab === tab.id
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'
                }`}
              >
                {tab.label}
              </button>
            ))}
        </div>
      </div>

      <div className="flex-1 overflow-auto p-6">
        {/* §22: every tab's visibility AND its content are both gated by the same canonical
           permission check — a user who reaches this page via a stale link but lacks the
           underlying permission sees the boundary fallback, not an empty/broken tab. */}
        {activeTab === 'users' ? (
          <PermissionBoundary permission="iam.users.view" fallback={<NoAccess />}>
            <UsersTab />
          </PermissionBoundary>
        ) : activeTab === 'roles' ? (
          <PermissionBoundary permission="iam.roles.view" fallback={<NoAccess />}>
            <RolesPermissionsTab
              onOpenTemplate={(key) => {
                setTemplateFocusKey(key);
                navigate(ROUTES.roleTemplates);
              }}
            />
          </PermissionBoundary>
        ) : (
          <PermissionBoundary permission="iam.role-templates.view" fallback={<NoAccess />}>
            <RoleTemplatesTab focusKey={templateFocusKey} onFocusHandled={() => setTemplateFocusKey(null)} />
          </PermissionBoundary>
        )}
      </div>
    </div>
  );
}

function NoAccess() {
  const { t } = useTranslation('iam-admin');
  return (
    <div className="text-muted-foreground flex flex-col items-center justify-center gap-2 py-16 text-sm">
      <p>{t(($) => $.workspace.noAccess)}</p>
    </div>
  );
}
