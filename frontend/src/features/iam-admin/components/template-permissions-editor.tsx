import { useMemo, useState } from 'react';
import { X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/crud';
import { usePermissionCatalogQuery } from '@/features/iam-admin/hooks/use-roles';

/**
 * §11: "do not obscure the canonical token entirely" — permissions are picked from the real
 * catalog (never free-typed), so a template can only ever reference a token that genuinely
 * exists; the compiler's own fail-closed validation (UnknownTemplatePermissionException) is
 * the ultimate backstop, this is just a friendlier way to avoid typos before submitting.
 */
export function TemplatePermissionsEditor({
  value,
  onChange,
}: {
  value: string[];
  onChange: (next: string[]) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const catalogQuery = usePermissionCatalogQuery();
  const [pending, setPending] = useState<string | null>(null);

  const options = useMemo(
    () =>
      (catalogQuery.data?.groups ?? [])
        .flatMap((group) => group.permissions)
        .filter((permission) => !value.includes(permission.name))
        .map((permission) => ({ value: permission.name, label: permission.name })),
    [catalogQuery.data, value],
  );

  function handleAdd() {
    if (!pending) return;
    onChange([...value, pending]);
    setPending(null);
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap gap-1.5">
        {value.length === 0 ? (
          <p className="text-muted-foreground text-xs">{t(($) => $.roleTemplates.form.noPermissions)}</p>
        ) : (
          value.map((permission) => (
            <Badge key={permission} variant="outline" className="gap-1 font-mono text-xs">
              {permission}
              <button
                type="button"
                aria-label={t(($) => $.roleTemplates.form.removePermission)}
                onClick={() => onChange(value.filter((p) => p !== permission))}
              >
                <X className="size-3" />
              </button>
            </Badge>
          ))
        )}
      </div>
      <div className="flex items-center gap-2">
        <Combobox
          options={options}
          value={pending}
          onChange={setPending}
          placeholder={t(($) => $.roleTemplates.form.addPermissionPlaceholder)}
        />
        <Button type="button" variant="outline" size="sm" onClick={handleAdd} disabled={!pending}>
          {t(($) => $.roleTemplates.form.addPermission)}
        </Button>
      </div>
    </div>
  );
}
