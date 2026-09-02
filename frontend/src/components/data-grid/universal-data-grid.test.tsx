import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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
import type { DataGridColumnDef } from './types';

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
