import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Can } from '@/features/authorization';
import { useSyncOrganizationScope } from '@/features/iam-admin/hooks/use-users';
import type { OrganizationScopeAssignmentInput, UserDetail } from '@/features/iam-admin/types/user';

import { OrganizationScopePicker } from './organization-scope-picker';

/**
 * Organization Scope (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §9).
 *
 * REPLACES the manual "Type / ID / Label" text-entry workflow entirely — §9: "Remove the
 * current manual generic Type / ID / Label workflow from normal admin use." What used to
 * be a free-typed org_id (completely unvalidated server-side until this task) is now a
 * selection over the canonical Company → Brand → Branch → Warehouse → Region → Channel →
 * Team → Business Unit hierarchy, saved as one complete set through
 * `UserOrganizationAssignmentService::sync()`, which validates every entity against its
 * real table before writing anything.
 */
export function UserOrganizationPanel({ user }: { user: UserDetail }) {
  const { t } = useTranslation('iam-admin');
  const sync = useSyncOrganizationScope(user.id);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState<OrganizationScopeAssignmentInput[]>(
    user.organizations.map((a) => ({ org_type: a.type, org_id: a.id, label: a.label ?? undefined, primary: a.is_primary })),
  );

  // Re-seed the draft whenever the server's own copy changes identity (a fresh fetch after
  // save, or switching to a different user) — adjusted during render (React's documented
  // pattern), not a useEffect.
  const [prevUser, setPrevUser] = useState(user);
  if (user !== prevUser) {
    setPrevUser(user);
    setDraft(
      user.organizations.map((a) => ({ org_type: a.type, org_id: a.id, label: a.label ?? undefined, primary: a.is_primary })),
    );
  }

  const dirty =
    JSON.stringify([...draft].sort((a, b) => `${a.org_type}${a.org_id}`.localeCompare(`${b.org_type}${b.org_id}`))) !==
    JSON.stringify(
      user.organizations
        .map((a) => ({ org_type: a.type, org_id: a.id, label: a.label ?? undefined, primary: a.is_primary }))
        .sort((a, b) => `${a.org_type}${a.org_id}`.localeCompare(`${b.org_type}${b.org_id}`)),
    );

  function handleSave() {
    setError(null);
    sync.mutate(draft, {
      onError: (err) =>
        setError(
          axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
            ? err.response.data.message
            : t(($) => $.users.detail.genericError),
        ),
    });
  }

  return (
    <div className="flex flex-col gap-4">
      {error ? (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}

      {user.organizations.length > 0 ? (
        <div className="flex flex-wrap gap-1.5">
          {user.organizations.map((assignment, index) => (
            <Badge key={`${assignment.type}-${assignment.id ?? index}`} variant="outline" className="gap-1">
              {assignment.label ?? assignment.type}
              {assignment.is_primary ? <span className="text-amber-500">★</span> : null}
            </Badge>
          ))}
        </div>
      ) : (
        <p className="text-muted-foreground text-sm">{t(($) => $.users.organization.none)}</p>
      )}

      <Can permission="iam.users.assign-org">
        <div className="border-t pt-4">
          <OrganizationScopePicker value={draft} onChange={setDraft} />
          <Button type="button" onClick={handleSave} disabled={!dirty || sync.isPending} className="mt-3">
            {sync.isPending ? t(($) => $.users.organization.saving) : t(($) => $.users.organization.save)}
          </Button>
        </div>
      </Can>
    </div>
  );
}
