import '@testing-library/jest-dom/vitest';
import { useState, type ReactNode } from 'react';
import { render, screen, fireEvent, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { LanguageContext } from '@/providers/language-context';

// TASK-ECOS-MOBILE-COMMERCE-SCREENS-UX-REFINEMENT-001 §10 — the mobile card
// list had no equivalent of the desktop <thead> "select all" checkbox, so
// there was no way to select every Order on the current page from a phone.
// These tests exercise the real `useRowSelection` hook (not a fake), so a
// pass here means the new control genuinely drives the same canonical
// selection state the desktop header and the bulk-action toolbar both read.
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
  }),
}));

import { UniversalDataGrid } from './universal-data-grid';
import { useRowSelection } from './use-row-selection';
import type { DataGridColumnDef, GridPaginationConfig, GridSortState } from './types';

type Row = { id: string; name: string };
const ROWS: Row[] = [{ id: '1', name: 'Alpha' }, { id: '2', name: 'Beta' }, { id: '3', name: 'Gamma' }];
// eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test-harness-only column label, not app UI
const COLUMNS: DataGridColumnDef<Row>[] = [{ key: 'name', label: 'Name', cell: (r) => r.name }];

function Harness({ onSelectionChange }: { onSelectionChange?: (ids: Set<string>) => void }) {
  const selection = useRowSelection({ items: ROWS, getId: (r) => r.id });
  onSelectionChange?.(selection.selectedIds);
  return (
    <UniversalDataGrid
      data={ROWS}
      columns={COLUMNS}
      rowId={(r) => r.id}
      selection={selection}
      renderMobileCard={(row, sel) => (
        <div key={row.id} role="listitem">
          <span>{row.name}</span>
          <input
            type="checkbox"
            aria-label={`row-${row.id}`}
            checked={sel?.isSelected(row.id) ?? false}
            onChange={(e) => sel?.selectRow(row.id, e.target.checked)}
          />
        </div>
      )}
    />
  );
}

// jsdom applies no real CSS (Tailwind's `lg:hidden`/`hidden lg:block` never
// actually hide anything here), so both the mobile card list AND the desktop
// <thead> render at once, each with their own "Select all" checkbox. The
// mobile one is rendered first in source order — `getAllByRole(...)[0]` reaches
// it deterministically without depending on the two happening to be visually
// distinguishable in this environment.
function getMobileSelectAll(): HTMLInputElement {
  return screen.getAllByRole('checkbox', { name: 'selection.selectAllRows' })[0] as HTMLInputElement;
}

describe('UniversalDataGrid — Mobile Select All (§10)', () => {
  it('renders a Select All control that selects every row on the current page', () => {
    render(<Harness />);
    fireEvent.click(getMobileSelectAll());
    for (const row of ROWS) {
      expect(screen.getByRole('checkbox', { name: `row-${row.id}` })).toBeChecked();
    }
  });

  it('shows the selected count once rows are selected', () => {
    render(<Harness />);
    expect(screen.queryByText(/mobile\.selectedCount/)).toBeNull();
    fireEvent.click(getMobileSelectAll());
    expect(screen.getByText(/mobile\.selectedCount/)).toBeInTheDocument();
  });

  it('clearing (unchecking) Select All clears every individual row', () => {
    render(<Harness />);
    const selectAll = getMobileSelectAll();
    fireEvent.click(selectAll);
    fireEvent.click(selectAll);
    for (const row of ROWS) {
      expect(screen.getByRole('checkbox', { name: `row-${row.id}` })).not.toBeChecked();
    }
    expect(screen.queryByText(/mobile\.selectedCount/)).toBeNull();
  });

  it('individual row checkboxes stay synchronized with Select All (partial → indeterminate, full → checked)', () => {
    render(<Harness />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'row-1' }));
    const selectAll = getMobileSelectAll();
    expect(selectAll.indeterminate).toBe(true);
    expect(selectAll.checked).toBe(false);

    fireEvent.click(screen.getByRole('checkbox', { name: 'row-2' }));
    fireEvent.click(screen.getByRole('checkbox', { name: 'row-3' }));
    expect(selectAll.indeterminate).toBe(false);
    expect(selectAll.checked).toBe(true);
  });

  it('does not render the Select All control when the grid has no selection API', () => {
    render(
      <UniversalDataGrid
        data={ROWS}
        columns={COLUMNS}
        rowId={(r) => r.id}
        renderMobileCard={(row) => <div key={row.id}>{row.name}</div>}
      />,
    );
    expect(screen.queryByRole('checkbox', { name: 'selection.selectAllRows' })).toBeNull();
  });
});

// TASK-ECOS-V1.1-CORE-01-UI-01-UNIVERSAL-DATAGRID-TEST-CLOSURE-045-R1 — first
// focused coverage for sorting/pagination/desktop-selection, closing the gap
// left by UI-01 (only the mobile Select All path above had tests). Exercises
// the real production component and its actual public contract as-is — no
// behavior was changed to make any of this easier to test.

describe('UniversalDataGrid — Sorting', () => {
  const SORT_COLUMNS: DataGridColumnDef<Row>[] = [
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test-harness-only column label, not app UI
    { key: 'name', label: 'Name', cell: (r) => r.name, sortable: true },
    { key: 'id', label: 'Id', cell: (r) => r.id },
  ];

  // The desktop table and the mobile auto-card fallback both render in jsdom
  // (no real CSS to hide either) — scoping every query to the <table> itself
  // avoids ever depending on which one happens to come first in source order.
  function sortButton() {
    return within(screen.getByRole('table')).getByRole('button', { name: /name/i });
  }

  it("clicking a sortable column header calls onSortChange with that column's key", async () => {
    const user = userEvent.setup();
    const onSortChange = vi.fn();
    render(
      <UniversalDataGrid data={ROWS} columns={SORT_COLUMNS} rowId={(r) => r.id} onSortChange={onSortChange} />,
    );
    await user.click(sortButton());
    expect(onSortChange).toHaveBeenCalledTimes(1);
    expect(onSortChange).toHaveBeenCalledWith('name');
  });

  it('renders a non-sortable column as plain text with no clickable sort control', () => {
    render(<UniversalDataGrid data={ROWS} columns={SORT_COLUMNS} rowId={(r) => r.id} onSortChange={vi.fn()} />);
    const table = screen.getByRole('table');
    expect(within(table).getByText('Id')).toBeInTheDocument();
    expect(within(table).queryByRole('button', { name: /^id$/i })).not.toBeInTheDocument();
  });

  it('renders a visually distinct sort indicator for ascending, descending, and unsorted, matching the `sort` prop', () => {
    const { rerender } = render(
      <UniversalDataGrid data={ROWS} columns={SORT_COLUMNS} rowId={(r) => r.id} onSortChange={vi.fn()} />,
    );
    const unsortedHtml = sortButton().innerHTML;

    rerender(
      <UniversalDataGrid
        data={ROWS} columns={SORT_COLUMNS} rowId={(r) => r.id} onSortChange={vi.fn()}
        sort={{ field: 'name', direction: 'asc' }}
      />,
    );
    const ascHtml = sortButton().innerHTML;

    rerender(
      <UniversalDataGrid
        data={ROWS} columns={SORT_COLUMNS} rowId={(r) => r.id} onSortChange={vi.fn()}
        sort={{ field: 'name', direction: 'desc' }}
      />,
    );
    const descHtml = sortButton().innerHTML;

    expect(ascHtml).not.toBe(unsortedHtml);
    expect(descHtml).not.toBe(unsortedHtml);
    expect(ascHtml).not.toBe(descHtml);
  });

  it('a caller driving sort state + reordering data (the real usage pattern) produces correctly ordered rows', async () => {
    const user = userEvent.setup();

    function SortingHarness() {
      const [sort, setSort] = useState<GridSortState | undefined>(undefined);
      const sorted = [...ROWS].sort((a, b) => {
        if (!sort || sort.field !== 'name') return 0;
        const cmp = a.name.localeCompare(b.name);
        return sort.direction === 'asc' ? cmp : -cmp;
      });
      return (
        <UniversalDataGrid
          data={sorted}
          columns={SORT_COLUMNS}
          rowId={(r) => r.id}
          sort={sort}
          onSortChange={(field) =>
            setSort((prev) =>
              prev?.field === field
                ? { field, direction: prev.direction === 'asc' ? 'desc' : 'asc' }
                : { field, direction: 'asc' },
            )
          }
        />
      );
    }

    function nameCellOrder() {
      return within(screen.getByRole('table'))
        .getAllByRole('cell')
        .map((c) => c.textContent)
        .filter((text): text is string => text === 'Alpha' || text === 'Beta' || text === 'Gamma');
    }

    render(<SortingHarness />);
    expect(nameCellOrder()).toEqual(['Alpha', 'Beta', 'Gamma']);

    await user.click(sortButton());
    expect(nameCellOrder()).toEqual(['Alpha', 'Beta', 'Gamma']);

    await user.click(sortButton());
    expect(nameCellOrder()).toEqual(['Gamma', 'Beta', 'Alpha']);
  });
});

describe('UniversalDataGrid — Pagination', () => {
  function meta(page: number, lastPage: number): GridPaginationConfig['meta'] {
    return { page, perPage: 10, total: lastPage * 10, lastPage };
  }

  // The canonical Pagination component reads useLanguage() (RTL-aware prev/next
  // icon choice) — a real dependency of the production component, not a test
  // artifact, so it needs a real LanguageContext value rather than being
  // stubbed away. This file already mocks react-i18next itself for speed, so
  // this provides just the context value directly instead of the full
  // LanguageProvider (which would pull in the real i18n instance + the now-
  // mocked I18nextProvider).
  function renderWithLang(node: ReactNode) {
    return render(
      <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
        {node}
      </LanguageContext.Provider>,
    );
  }

  it('calls onPageChange(page + 1) when Next is clicked', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();
    renderWithLang(
      <UniversalDataGrid
        data={ROWS} columns={COLUMNS} rowId={(r) => r.id}
        pagination={{ meta: meta(2, 5), onPageChange }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /next/i }));
    expect(onPageChange).toHaveBeenCalledWith(3);
  });

  it('calls onPageChange(page - 1) when Previous is clicked', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();
    renderWithLang(
      <UniversalDataGrid
        data={ROWS} columns={COLUMNS} rowId={(r) => r.id}
        pagination={{ meta: meta(2, 5), onPageChange }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /previous/i }));
    expect(onPageChange).toHaveBeenCalledWith(1);
  });

  it('disables Previous on page 1 and never calls onPageChange from it', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();
    renderWithLang(
      <UniversalDataGrid
        data={ROWS} columns={COLUMNS} rowId={(r) => r.id}
        pagination={{ meta: meta(1, 5), onPageChange }}
      />,
    );
    const prev = screen.getByRole('button', { name: /previous/i });
    expect(prev).toBeDisabled();
    await user.click(prev);
    expect(onPageChange).not.toHaveBeenCalled();
  });

  it('disables Next on the last page and never calls onPageChange from it', async () => {
    const user = userEvent.setup();
    const onPageChange = vi.fn();
    renderWithLang(
      <UniversalDataGrid
        data={ROWS} columns={COLUMNS} rowId={(r) => r.id}
        pagination={{ meta: meta(5, 5), onPageChange }}
      />,
    );
    const next = screen.getByRole('button', { name: /next/i });
    expect(next).toBeDisabled();
    await user.click(next);
    expect(onPageChange).not.toHaveBeenCalled();
  });

  it('does not render pagination controls while loading, even with a pagination config supplied', () => {
    renderWithLang(
      <UniversalDataGrid
        data={ROWS} columns={COLUMNS} rowId={(r) => r.id} loading
        pagination={{ meta: meta(2, 5), onPageChange: vi.fn() }}
      />,
    );
    expect(screen.queryByRole('button', { name: /next/i })).not.toBeInTheDocument();
  });

  it('does not render pagination controls when no pagination config is supplied', () => {
    render(<UniversalDataGrid data={ROWS} columns={COLUMNS} rowId={(r) => r.id} />);
    expect(screen.queryByRole('button', { name: /next/i })).not.toBeInTheDocument();
  });
});

describe('UniversalDataGrid — Row Selection (desktop)', () => {
  function DesktopSelectionHarness() {
    const selection = useRowSelection({ items: ROWS, getId: (r) => r.id });
    return <UniversalDataGrid data={ROWS} columns={COLUMNS} rowId={(r) => r.id} selection={selection} />;
  }

  function desktopTable() {
    return screen.getByRole('table');
  }

  // Scoped to the <tr> containing this row's own name cell rather than a
  // shared/generic aria-label (every desktop row checkbox carries the same
  // generic `selection.selectRow` label — only the surrounding row makes it
  // row-specific), and scoped to the table so it never matches the parallel
  // mobile auto-card render of the same row.
  function desktopRow(name: string) {
    return within(desktopTable()).getByText(name).closest('tr')!;
  }

  it('selects and deselects an individual row via its desktop checkbox', async () => {
    const user = userEvent.setup();
    render(<DesktopSelectionHarness />);
    const checkbox = within(desktopRow('Alpha')).getByRole('checkbox');
    expect(checkbox).not.toBeChecked();
    await user.click(checkbox);
    expect(checkbox).toBeChecked();
    await user.click(checkbox);
    expect(checkbox).not.toBeChecked();
  });

  it('supports selecting multiple independent rows at once', async () => {
    const user = userEvent.setup();
    render(<DesktopSelectionHarness />);
    await user.click(within(desktopRow('Alpha')).getByRole('checkbox'));
    await user.click(within(desktopRow('Gamma')).getByRole('checkbox'));
    expect(within(desktopRow('Alpha')).getByRole('checkbox')).toBeChecked();
    expect(within(desktopRow('Beta')).getByRole('checkbox')).not.toBeChecked();
    expect(within(desktopRow('Gamma')).getByRole('checkbox')).toBeChecked();
  });

  it('desktop header Select All selects/deselects every row, with correct indeterminate state in between', async () => {
    const user = userEvent.setup();
    render(<DesktopSelectionHarness />);
    const headerCheckbox = within(desktopTable()).getByRole('checkbox', {
      name: 'selection.selectAllRows',
    }) as HTMLInputElement;

    await user.click(within(desktopRow('Alpha')).getByRole('checkbox'));
    expect(headerCheckbox.indeterminate).toBe(true);
    expect(headerCheckbox.checked).toBe(false);

    await user.click(headerCheckbox);
    expect(within(desktopRow('Alpha')).getByRole('checkbox')).toBeChecked();
    expect(within(desktopRow('Beta')).getByRole('checkbox')).toBeChecked();
    expect(within(desktopRow('Gamma')).getByRole('checkbox')).toBeChecked();

    await user.click(headerCheckbox);
    expect(within(desktopRow('Alpha')).getByRole('checkbox')).not.toBeChecked();
    expect(within(desktopRow('Beta')).getByRole('checkbox')).not.toBeChecked();
    expect(within(desktopRow('Gamma')).getByRole('checkbox')).not.toBeChecked();
  });

  it('keeps the GridSelectionAPI (selectedCount/isSelected) consistent with what the desktop UI shows', async () => {
    const user = userEvent.setup();
    // Reports the live selection API out via a callback prop (same pattern as
    // the existing Harness's onSelectionChange above) rather than reassigning
    // an outer-scoped variable during render, which the project's react-hooks
    // purity rule correctly rejects as a side effect.
    const onSelectionChange = vi.fn();
    function Harness2() {
      const selection = useRowSelection({ items: ROWS, getId: (r) => r.id });
      onSelectionChange(selection);
      return <UniversalDataGrid data={ROWS} columns={COLUMNS} rowId={(r) => r.id} selection={selection} />;
    }
    render(<Harness2 />);
    await user.click(within(desktopRow('Beta')).getByRole('checkbox'));
    const latest = onSelectionChange.mock.calls.at(-1)?.[0] as ReturnType<typeof useRowSelection<Row>>;
    expect(latest.isSelected('2')).toBe(true);
    expect(latest.selectedCount).toBe(1);
    expect(latest.allSelected).toBe(false);
  });
});
