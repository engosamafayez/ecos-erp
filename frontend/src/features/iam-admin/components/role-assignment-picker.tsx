import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { EcosMultiCombobox } from '@/components/ui/ecos-multi-combobox';
import { useRoleTemplatesQuery } from '@/features/iam-admin/hooks/use-role-templates';

/**
 * Role assignment picker for Create/Edit User (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001,
 * §8).
 *
 * A multi-select over PUBLISHED Role Templates — draft templates are not assignable
 * (`RoleTemplateStatus::isAssignable()`), so they are filtered out here rather than
 * offered and rejected server-side. Selecting a template here means the same thing it
 * always has: on save, `UserRoleAssignmentService::assignTemplate()` compiles it and
 * attaches the resulting runtime Role — this component only builds the list of keys, it
 * performs no assignment itself and is not a second authorization path.
 *
 * Labels lead with the Arabic business name (§5) for the approved fourteen-role catalogue,
 * falling back to the template's own name otherwise.
 */
export function RoleAssignmentPicker({
  value,
  onChange,
}: {
  value: string[];
  onChange: (next: string[]) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const query = useRoleTemplatesQuery();

  const options = useMemo(
    () =>
      (query.data ?? [])
        .filter((template) => template.status === 'published')
        .map((template) => ({
          value: template.key,
          label: template.name_ar !== template.name ? `${template.name_ar} (${template.name})` : template.name,
        })),
    [query.data],
  );

  return (
    <EcosMultiCombobox
      options={options}
      value={value}
      onChange={onChange}
      loading={query.isLoading}
      placeholder={t(($) => $.users.roles.assignPlaceholder)}
      searchPlaceholder={t(($) => $.users.roles.searchPlaceholder)}
      emptyText={t(($) => $.users.roles.noneAvailable)}
      optionsLabel={t(($) => $.users.roles.assignPlaceholder)}
    />
  );
}
