import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { searchAddressableUsers } from '../services/collaboration-service';

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 300;

/**
 * Backs every "pick somebody to address" field (new direct conversation, new group
 * member, task assignee/reassignment) — never a raw numeric-id input. `query`/
 * `setQuery` track keystrokes immediately (no input lag); the actual network search
 * is debounced and additionally requires 2+ characters, matching the backend's own
 * `q` validation and avoiding a request per keystroke — EcosCombobox's
 * `onSearchChange` fires on every change with no debouncing of its own.
 */
export function useUserSearch() {
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const id = setTimeout(() => setDebounced(query.trim()), DEBOUNCE_MS);
    return () => clearTimeout(id);
  }, [query]);

  const enabled = debounced.length >= MIN_QUERY_LENGTH;

  const { data, isFetching } = useQuery({
    queryKey: ['collaboration', 'search', 'users', debounced],
    queryFn: () => searchAddressableUsers(debounced),
    enabled,
  });

  return {
    query,
    setQuery,
    results: data ?? [],
    isSearching: enabled && isFetching,
    isQueryTooShort: query.trim().length > 0 && !enabled,
  };
}
