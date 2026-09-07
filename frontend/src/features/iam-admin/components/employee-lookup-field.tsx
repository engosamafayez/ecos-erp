import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/ecos-popover';
import { cn } from '@/lib/utils';
import { useEmployeeDirectoryQuery } from '@/features/iam-admin/hooks/use-users';
import type { EmployeeLookupEntry } from '@/features/iam-admin/types/user';

/**
 * Searchable EXISTING-employee lookup (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §6).
 *
 * Replaces the manual Employee Number text field. The value this component edits is still
 * the employee's canonical `employee_number` — §6 asks for a real link to an existing
 * employee record, not a new relationship column — but it is now SELECTED from
 * `EmployeeDirectory` rather than typed, so it can never name an employee who does not
 * exist. Server-side, `UserIdentityService::assertEmployeeLink()` verifies it again before
 * anything is saved.
 *
 * `currentEmployeeId` is the id of the employee already linked (edit mode) — needed
 * because the directory hides already-linked employees by default (`onlyUnlinked`) and the
 * currently-linked one must still resolve and display.
 */
export function EmployeeLookupField({
  value,
  onChange,
  currentEmployeeId,
}: {
  /** The selected employee's canonical `employee_number`, or null for none. */
  value: string | null;
  onChange: (employeeNumber: string | null) => void;
  currentEmployeeId?: string | null;
}) {
  const { t } = useTranslation('iam-admin');
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  // TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-FOCUS-REMEDIATION-006 §4/§6 — same
  // root cause and fix as EcosCombobox (see its own comment for the full
  // mechanism): a Dialog/Sheet's FocusScope traps focus to its own DOM subtree,
  // and this Popover's content otherwise portals to `document.body` — a DOM
  // sibling, never a descendant — so the search input below could never hold
  // focus while this field is used inside a Create/Edit User Sheet. Portal
  // into the nearest ancestor dialog's own content node instead when one
  // exists. Computed inside the effect below (an approved place to read a
  // ref's current value), never during render.
  const [portalContainer, setPortalContainer] = useState<HTMLElement | null>(null);
  useEffect(() => {
    if (open) {
      setPortalContainer(triggerRef.current?.closest<HTMLElement>('[role="dialog"]') ?? null);
    }
  }, [open]);
  const [search, setSearch] = useState('');
  // §4 — every other server-searched selector in this codebase debounces the typed term
  // (ProductLineSelect, supplier/warehouse pickers); this one fired a request on every
  // keystroke. Matches the same 250ms convention.
  const [debouncedSearch, setDebouncedSearch] = useState('');
  useEffect(() => {
    const id = setTimeout(() => setDebouncedSearch(search), 250);
    return () => clearTimeout(id);
  }, [search]);
  const query = useEmployeeDirectoryQuery(debouncedSearch, currentEmployeeId == null);

  const selected = useMemo(
    () => query.data?.data.find((e) => e.employee_number === value) ?? null,
    [query.data, value],
  );

  if (query.data && !query.data.available) {
    // No canonical employee directory in this installation — nothing to look up against.
    // §6 forbids free-text entry as the replacement, so this degrades to "not available"
    // rather than silently reopening a text field.
    return <p className="text-muted-foreground text-xs">{t(($) => $.users.employee.notAvailable)}</p>;
  }

  return (
    <div className="flex items-center gap-2">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button ref={triggerRef} type="button" variant="outline" className="min-w-0 flex-1 justify-between font-normal">
            <span className="truncate">
              {selected
                ? `${selected.employee_number ?? ''} — ${selected.name}`
                : t(($) => $.users.employee.placeholder)}
            </span>
            <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent container={portalContainer} className="w-[320px] p-0">
          <div className="border-b p-2">
            <Input
              autoFocus
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t(($) => $.users.employee.searchPlaceholder)}
            />
          </div>
          <div className="max-h-64 overflow-y-auto p-1">
            {query.isLoading ? (
              <p className="text-muted-foreground p-3 text-xs">{t(($) => $.users.employee.loading)}</p>
            ) : (query.data?.data.length ?? 0) === 0 ? (
              <p className="text-muted-foreground p-3 text-xs">{t(($) => $.users.employee.empty)}</p>
            ) : (
              query.data?.data.map((employee: EmployeeLookupEntry) => {
                const disabled = employee.linked_user_id !== null && employee.employee_number !== value;
                const isSelected = employee.employee_number === value;
                return (
                  <button
                    key={employee.id}
                    type="button"
                    disabled={disabled}
                    onClick={() => {
                      onChange(employee.employee_number);
                      setOpen(false);
                    }}
                    className={cn(
                      'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm',
                      disabled ? 'cursor-not-allowed opacity-50' : 'hover:bg-muted/60',
                    )}
                  >
                    <Check className={cn('size-3.5 shrink-0', isSelected ? 'opacity-100' : 'opacity-0')} />
                    <div className="min-w-0 flex-1">
                      <div className="truncate">
                        <span className="font-mono text-xs">{employee.employee_number ?? '—'}</span>{' '}
                        <span>{employee.name}</span>
                      </div>
                      {employee.work_email ? (
                        <div className="text-muted-foreground truncate text-xs">{employee.work_email}</div>
                      ) : null}
                    </div>
                    {disabled ? (
                      <span className="text-muted-foreground text-[10px]">
                        {t(($) => $.users.employee.alreadyLinked)}
                      </span>
                    ) : null}
                  </button>
                );
              })
            )}
          </div>
        </PopoverContent>
      </Popover>

      {value ? (
        <Button type="button" variant="ghost" size="icon" onClick={() => onChange(null)} aria-label={t(($) => $.users.employee.clear)}>
          <X className="size-4" />
        </Button>
      ) : null}
    </div>
  );
}
