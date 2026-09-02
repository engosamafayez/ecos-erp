import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Can } from '@/features/authorization';
import { useAssignOrganization } from '@/features/iam-admin/hooks/use-users';
import type { UserDetail } from '@/features/iam-admin/types/user';

/** Matches UserOrganizationAssignmentService::TYPES exactly (backend, Task 2). */
const ORG_TYPES = [
  'company',
  'branch',
  'warehouse',
  'department',
  'business_unit',
  'region',
  'channel',
  'team',
  'cost_center',
] as const;

export function UserOrganizationPanel({ user }: { user: UserDetail }) {
  const { t } = useTranslation('iam-admin');
  const assign = useAssignOrganization(user.id);
  const [orgType, setOrgType] = useState<string>('');
  const [orgId, setOrgId] = useState('');
  const [label, setLabel] = useState('');
  const [error, setError] = useState<string | null>(null);

  function handleAssign() {
    if (!orgType) return;
    setError(null);
    assign.mutate(
      { org_type: orgType, org_id: orgId || undefined, label: label || undefined },
      {
        onSuccess: () => {
          setOrgId('');
          setLabel('');
        },
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
        {user.organizations.length === 0 ? (
          <p className="text-muted-foreground text-sm">{t(($) => $.users.organization.none)}</p>
        ) : (
          user.organizations.map((assignment, index) => (
            <div key={`${assignment.type}-${assignment.id ?? index}`} className="rounded-md border px-3 py-2 text-sm">
              <span className="font-medium">{assignment.label ?? assignment.type}</span>
              <span className="text-muted-foreground ms-2 text-xs">
                {assignment.type}
                {assignment.id ? ` · ${assignment.id}` : ''}
                {assignment.is_primary ? ` · ${t(($) => $.users.organization.primary)}` : ''}
              </span>
            </div>
          ))
        )}
      </div>

      <Can permission="iam.users.assign-org">
        <div className="flex flex-col gap-2 border-t pt-4">
          <div className="flex gap-2">
            <Select value={orgType} onValueChange={setOrgType}>
              <SelectTrigger className="w-40">
                <SelectValue placeholder={t(($) => $.users.organization.typePlaceholder)} />
              </SelectTrigger>
              <SelectContent>
                {ORG_TYPES.map((type) => (
                  <SelectItem key={type} value={type}>
                    {type}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Input
              placeholder={t(($) => $.users.organization.idPlaceholder)}
              value={orgId}
              onChange={(event) => setOrgId(event.target.value)}
            />
            <Input
              placeholder={t(($) => $.users.organization.labelPlaceholder)}
              value={label}
              onChange={(event) => setLabel(event.target.value)}
            />
          </div>
          <Button type="button" onClick={handleAssign} disabled={!orgType || assign.isPending} className="self-end">
            {t(($) => $.users.organization.assign)}
          </Button>
        </div>
      </Can>
    </div>
  );
}
