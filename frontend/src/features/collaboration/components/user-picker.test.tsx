import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import type { EcosComboboxOption, EcosComboboxProps } from '@/components/ui/ecos-combobox';

function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    i18n: { language: 'en', exists: () => true },
  }),
}));

vi.mock('@/components/crud', () => ({
  Combobox: ({ options, onChange, onSearchChange, loading }: EcosComboboxProps) => (
    <div>
      <input data-testid="cb-search" onChange={(e) => onSearchChange?.(e.target.value)} />
      {loading ? (
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock placeholder text asserted on by the test, not real UI copy
        <span>loading</span>
      ) : (
        options.map((o: EcosComboboxOption) => (
          <button key={o.value} type="button" onClick={() => onChange(o.value)}>{o.label}</button>
        ))
      )}
    </div>
  ),
}));

import { useUserSearch } from '../hooks/use-user-search';
vi.mock('../hooks/use-user-search', () => ({ useUserSearch: vi.fn() }));

import { UserPicker } from './user-picker';
import type { AddressableUser } from '../types';

const mockUseUserSearch = vi.mocked(useUserSearch);

function withSearch(over: Partial<{ query: string; setQuery: (q: string) => void; results: AddressableUser[]; isSearching: boolean }> = {}) {
  mockUseUserSearch.mockReturnValue({
    query: over.query ?? '',
    setQuery: over.setQuery ?? vi.fn(),
    results: over.results ?? [],
    isSearching: over.isSearching ?? false,
    isQueryTooShort: false,
  } as unknown as ReturnType<typeof useUserSearch>);
}

const ALICE: AddressableUser = { id: 1, name: 'Alice', job_title: null, is_driver: false };
const BOB: AddressableUser = { id: 2, name: 'Bob', job_title: null, is_driver: false };
const SAM_DRIVER: AddressableUser = { id: 3, name: 'Sam', job_title: null, is_driver: true };

describe('UserPicker', () => {
  beforeEach(() => {
    mockUseUserSearch.mockReset();
  });

  it('reflects the search results, filtered by excludeIds', () => {
    withSearch({ results: [ALICE, BOB] });
    render(<UserPicker value={null} onChange={vi.fn()} excludeIds={[2]} />);

    expect(screen.getByText('Alice')).toBeInTheDocument();
    expect(screen.queryByText('Bob')).not.toBeInTheDocument();
  });

  it('calls setQuery when typing in the search input', () => {
    const setQuery = vi.fn();
    withSearch({ setQuery });
    render(<UserPicker value={null} onChange={vi.fn()} />);

    fireEvent.change(screen.getByTestId('cb-search'), { target: { value: 'ali' } });
    expect(setQuery).toHaveBeenCalledWith('ali');
  });

  it('suffixes a driver result with the driver indicator text', () => {
    withSearch({ results: [SAM_DRIVER] });
    render(<UserPicker value={null} onChange={vi.fn()} />);

    expect(
      screen.getByText((_, el) => el?.tagName.toLowerCase() === 'button' && (el?.textContent?.includes('tasks.context.driver') ?? false)),
    ).toBeInTheDocument();
  });

  it('calls onChange with the full matching user object when an option is clicked', () => {
    const onChange = vi.fn();
    withSearch({ results: [ALICE, BOB] });
    render(<UserPicker value={null} onChange={onChange} />);

    fireEvent.click(screen.getByText('Alice'));
    expect(onChange).toHaveBeenCalledWith(ALICE);
  });

  it('keeps the currently selected value resolvable even if it fell out of the live results', () => {
    withSearch({ results: [ALICE] });
    render(<UserPicker value={{ id: 99, name: 'Zed', job_title: null, is_driver: false }} onChange={vi.fn()} />);

    expect(screen.getByText('Zed')).toBeInTheDocument();
    expect(screen.getByText('Alice')).toBeInTheDocument();
  });
});
