import '@testing-library/jest-dom/vitest';
import { useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { EcosCombobox } from './ecos-combobox';

// jsdom does not implement scrollIntoView (used by the active-option scroll effect).
Element.prototype.scrollIntoView = Element.prototype.scrollIntoView ?? (() => {});
import { Sheet, SheetContent } from './sheet';

/**
 * TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-FOCUS-REMEDIATION-006.
 *
 * The nested-in-Sheet cases below are the actual regression coverage for the reported
 * defect: a Radix Dialog/Sheet traps focus to its own DOM container
 * (`@radix-ui/react-focus-scope`'s `container.contains(event.target)`); this
 * component's Popover content previously portalled to `document.body` — a DOM
 * sibling of the Sheet's own portalled content, never a descendant — so any focus
 * landing inside it was immediately yanked back by the Sheet's trap, and the search
 * input could never be typed into. See ecos-combobox.tsx's own root-cause comment.
 */

const OPTIONS = [
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture option labels
  { value: 'a', label: 'Alpha' },
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture option labels
  { value: 'b', label: 'Beta' },
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture option labels
  { value: 'c', label: 'Gamma' },
];

function Bare({ multipleInstances = false }: { multipleInstances?: boolean }) {
  const [value, setValue] = useState<string | null>(null);
  const [value2, setValue2] = useState<string | null>(null);
  return (
    <>
      <EcosCombobox options={OPTIONS} value={value} onChange={setValue} searchPlaceholder="Search…" />
      {multipleInstances && (
        <EcosCombobox
          options={OPTIONS}
          value={value2}
          onChange={setValue2}
          // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture placeholder
          placeholder="Select 2…"
          searchPlaceholder="Search 2…"
        />
      )}
    </>
  );
}

function InsideSheet() {
  const [value, setValue] = useState<string | null>(null);
  return (
    <Sheet open onOpenChange={() => {}}>
      <SheetContent>
        <EcosCombobox options={OPTIONS} value={value} onChange={setValue} searchPlaceholder="Search…" />
      </SheetContent>
    </Sheet>
  );
}

describe('EcosCombobox — standalone', () => {
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
    expect(screen.queryByText('Gamma')).not.toBeInTheDocument();
  });

  it('clearing the query restores every option', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await user.keyboard('Bet');
    await user.clear(input);
    expect(screen.getByText('Alpha')).toBeInTheDocument();
    expect(screen.getByText('Beta')).toBeInTheDocument();
    expect(screen.getByText('Gamma')).toBeInTheDocument();
  });

  it('selecting a result commits the value and closes the dropdown', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    await screen.findByPlaceholderText('Search…');
    await user.click(screen.getByText('Beta'));
    expect(await screen.findByText('Beta', { selector: 'span.truncate' })).toBeInTheDocument();
    expect(screen.queryByPlaceholderText('Search…')).not.toBeInTheDocument();
  });

  it('Arrow Down/Enter keyboard navigation selects a result', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}');
    expect(await screen.findByText('Beta', { selector: 'span.truncate' })).toBeInTheDocument();
  });

  it('Escape closes the dropdown without changing the value', async () => {
    const user = userEvent.setup();
    render(<Bare />);
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByPlaceholderText('Search…')).not.toBeInTheDocument());
  });

  it('two independent instances do not steal focus from one another', async () => {
    const user = userEvent.setup();
    render(<Bare multipleInstances />);
    await user.click(screen.getByRole('button', { name: /^select…$/i }));
    const firstInput = await screen.findByPlaceholderText('Search…');
    await user.click(firstInput);
    expect(document.activeElement).toBe(firstInput);

    await user.keyboard('{Escape}');
    await user.click(screen.getByRole('button', { name: /select 2/i }));
    const secondInput = await screen.findByPlaceholderText('Search 2…');
    await user.click(secondInput);
    expect(document.activeElement).toBe(secondInput);
    await user.keyboard('Al');
    expect(secondInput).toHaveValue('Al');
  });
});

describe('EcosCombobox — nested inside a Sheet (the reported defect)', () => {
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

  it('dropdown stays open while the input is focused and typed into, nested inside a Sheet', async () => {
    const user = userEvent.setup();
    render(<InsideSheet />);
    await waitFor(() => expect(screen.getByRole('dialog')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: /select/i }));
    const input = await screen.findByPlaceholderText('Search…');
    await user.click(input);
    await user.keyboard('a');
    expect(screen.getByPlaceholderText('Search…')).toBeInTheDocument();
  });
});
