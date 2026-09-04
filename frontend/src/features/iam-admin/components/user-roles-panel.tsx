import { useState } from 'react';
import axios from 'axios';
import { Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Can } from '@/features/authorization';
import { useAssignTemplate, useRevokeTemplate } from '@/features/iam-admin/hooks/use-users';
import { useRoleTemplatesQuery } from '@/features/iam-admin/hooks/use-role-templates';
import type { UserDetail } from '@/features/iam-admin/types/user';

/**
 * §8: role assignment/removal from the User detail. §23: only templates this actor may
 * legitimately see are ever offered — the /iam/role-templates list is already tenant-scoped
 * server-side (system templates + this actor's own company's custom ones), so no foreign
 * template can appear here by construction; this panel adds no client-side filtering of its
 * own on top of that, because inventing one risks disagreeing with the server's own boundary.
 */
export function UserRolesPanel({ user }: { user: UserDetail }) {
  const { t } = useTranslation('iam-admin');
  const templatesQuery = useRoleTemplatesQuery();
  const assignTemplate = useAssignTemplate(user.id);
  const revokeTemplate = useRevokeTemplate(user.id);
  const [selectedKey, setSelectedKey] = useState<string>('');
  const [error, setError] = useState<string | null>(null);

  const assignedKeys = new Set(user.templates.map((assignment) => assignment.key));
  // §14/§9: an unresolved-permission template still shows in the catalog but cannot compile —
  // assigning it would fail server-side (UnknownTemplatePermissionException, 422). Templates
  // don't currently report their own resolution state via the list endpoint, so this panel
  // relies on that 422 surfacing plainly rather than guessing which templates are safe.
  const assignable = (templatesQuery.data ?? []).filter(
    (template) => template.status === 'published' && !assignedKeys.has(template.key),
  );

  function handleAssign() {
    if (!selectedKey) return;
    setError(null);
    assignTemplate.mutate(
      { templateKey: selectedKey },
      {
        onSuccess: () => setSelectedKey(''),
        onError: (err) =>
          setError(
            axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
              ? err.response.data.message
              : t(($) => $.users.detail.genericError),
          ),
      },
    );
  }

  return (
    <div className="flex flex-col gap-4">
      {error ? (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}

      <div className="flex flex-col gap-2">
        {user.templates.length === 0 ? (
          <p className="text-muted-foreground text-sm">{t(($) => $.users.roles.none)}</p>
        ) : (
          user.templates.map((assignment) => (
            <div
              key={assignment.key ?? assignment.name}
              className="flex items-center justify-between rounded-md border px-3 py-2"
            >
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium">{assignment.name}</span>
                {assignment.is_primary ? (
                  <Badge variant="outline" className="text-xs">
                    {t(($) => $.users.roles.primary)}
                  </Badge>
                ) : null}
              </div>
              <Can permission="iam.users.revoke-role">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  aria-label={t(($) => $.users.roles.revoke)}
                  disabled={revokeTemplate.isPending || !assignment.key}
                  onClick={() => assignment.key && revokeTemplate.mutate(assignment.key)}
                >
                  <Trash2 className="text-destructive size-4" />
                </Button>
              </Can>
            </div>
          ))
        )}
      </div>

      <Can permission="iam.users.assign-role">
        <div className="flex items-center gap-2 border-t pt-4">
          <Select value={selectedKey} onValueChange={setSelectedKey}>
            <SelectTrigger className="flex-1">
              <SelectValue placeholder={t(($) => $.users.roles.selectPlaceholder)} />
            </SelectTrigger>
            <SelectContent>
              {assignable.map((template) => (
                <SelectItem key={template.key} value={template.key}>
                  {template.name}
                  {template.is_system ? ` (${t(($) => $.roleTemplates.systemBadge)})` : ''}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button type="button" onClick={handleAssign} disabled={!selectedKey || assignTemplate.isPending}>
            {t(($) => $.users.roles.assign)}
          </Button>
        </div>
      </Can>
    </div>
  );
}
