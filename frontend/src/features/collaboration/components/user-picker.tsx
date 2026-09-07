import { useTranslation } from 'react-i18next';

import { Combobox } from '@/components/crud';

import { useUserSearch } from '../hooks/use-user-search';
import type { AddressableUser } from '../types';

type UserPickerProps = {
  value: AddressableUser | null;
  onChange: (user: AddressableUser | null) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  /** Ids to hide from results (e.g. people already added to this group). */
  excludeIds?: number[];
};

/**
 * The one shared "pick somebody to address" field — new direct conversation, new
 * group member, task assignee/reassignment. Never a raw numeric-id input. Backed by
 * GET /collaboration/search/users (SearchAddressableUsersAction): company-scoped,
 * excludes the current user, and only surfaces a driver-linked candidate the actor is
 * both permitted and in-scope to message — a driver who doesn't appear here yet is
 * that capability gate, not a bug in this picker.
 */
export function UserPicker({ value, onChange, placeholder, disabled, className, excludeIds = [] }: UserPickerProps) {
  const { t } = useTranslation('collaboration');
  const { setQuery, results, isSearching, isError } = useUserSearch();

  const candidates = results.filter((u) => !excludeIds.includes(u.id));
  // Keeps the currently selected person resolvable in the trigger label even after
  // the search text moves on and they fall out of the live `results` page.
  const merged = value && !candidates.some((u) => u.id === value.id) ? [value, ...candidates] : candidates;

  const options = merged.map((u) => ({
    value: String(u.id),
    label: u.is_driver ? `${u.name} · ${t(($) => $.tasks.context.driver)}` : u.name,
  }));

  return (
    <Combobox
      options={options}
      value={value ? String(value.id) : null}
      onChange={(id) => onChange(merged.find((u) => String(u.id) === id) ?? null)}
      onSearchChange={setQuery}
      filterClientSide={false}
      loading={isSearching}
      placeholder={placeholder ?? t(($) => $.conversations.newDirectDialog.recipientPlaceholder)}
      searchPlaceholder={t(($) => $.conversations.newDirectDialog.recipientPlaceholder)}
      emptyText={isError ? t(($) => $.search.error) : t(($) => $.search.noResults)}
      disabled={disabled}
      className={className}
    />
  );
}
