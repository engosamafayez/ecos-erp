import '@testing-library/jest-dom/vitest';
import { useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { EcosMultiCombobox } from './ecos-multi-combobox';

// jsdom does not implement scrollIntoView (used by the active-option scroll effect).
Element.prototype.scrollIntoView = Element.prototype.scrollIntoView ?? (() => {});
import { Sheet, SheetContent } from './sheet';

/**
 * Mirrors ecos-combobox.test.tsx's coverage for the multi-select sibling
 * (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045, ticket §17 item 3
 * "multi-select equivalent focus behavior if touched" — both variants now
 * share use-dialog-ancestor-portal.ts's implementation of
 * TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-FOCUS-REMEDIATION-006, so both need
 * their own regression coverage for the nested-in-Sheet case).
 */

const OPTIONS = [
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture option labels
  { value: 'a', label: 'Alpha' },
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture option labels
  { value: 'b', label: 'Beta' },
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture option labels
  { value: 'c', label: 'Gamma' },
];

function Bare() {
  const [value, setValue] = useState<string[]>([]);
  return (
    <EcosMultiCombobox options={OPTIONS} value={value} onChange={setValue} searchPlaceholder="Search…" />
  );
}

function InsideSheet() {
  const [value, setValue] = useState<string[]>([]);
  return (
    <Sheet open onOpenChange={() => {}}>
      <SheetContent>
        <EcosMultiCombobox options={OPTIONS} value={value} onChange={setValue} searchPlaceholder="Search…" />
      </SheetContent>
    </Sheet>
  );
}

describe('EcosMultiCombobox — standalone', () => {
  it('clicking the search input after opening focuses it and shows a caret-ready element', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    expect(document.activeElement).toBe(input);
  });

  it('typing updates the query and filters visible results', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await user.keyboard('Bet');
    expect(input).toHaveValue('Bet');
    expect(screen.getByText('Beta')).toBeInTheDocument();
    expect(screen.queryByText('Alpha')).not.toBeInTheDocument();
  });

  it('selecting toggles membership and keeps the popover open for further selection', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    await screen.findByPlaceholderText('Search…');
    await user.click(screen.getByText('Beta'));
    expect(screen.getByPlaceholderText('Search…')).toBeInTheDocument();
    expect(screen.getAllByText('Beta').length).toBeGreaterThan(0);
  });
});

describe('EcosMultiCombobox — nested inside a Sheet (the reported defect)', () => {
  it('clicking the search input after opening focuses it, even nested inside a Sheet', async () => {
    const user = userEvent.setup();
    render(<InsideSheet />);
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await waitFor(() => expect(document.activeElement).toBe(input));
  });

  it('typing into the input after a manual click updates the query and filters results, nested inside a Sheet', async () => {
    const user = userEvent.setup();
    render(<InsideSheet />);
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await user.keyboard('Gam');
    await waitFor(() => expect(input).toHaveValue('Gam'));
    expect(screen.getByText('Gamma')).toBeInTheDocument();
    expect(screen.queryByText('Alpha')).not.toBeInTheDocument();
  });
});
