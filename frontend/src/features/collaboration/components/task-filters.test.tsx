import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
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

// jsdom lacks the pointer-capture APIs Radix Select needs — swap in a native <select>.
vi.mock('@/components/ui/select', () => ({
  Select: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) => (
    <select data-testid="select" value={value} onChange={(e) => onValueChange(e.target.value)}>{children}</select>
  ),
  SelectTrigger: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectValue: () => null,
  SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectItem: ({ value, children }: { value: string; children: ReactNode }) => <option value={value}>{children}</option>,
}));

import { TaskFilters } from './task-filters';
import type { TaskFilters as TaskFiltersValue } from '../types';

function selects(): HTMLSelectElement[] {
  return screen.getAllByTestId('select') as unknown as HTMLSelectElement[];
}

describe('TaskFilters', () => {
  it('defaults the scope select to "mine" when value.scope is undefined', () => {
    render(<TaskFilters value={{}} onChange={() => {}} />);
    expect(selects()[0].value).toBe('mine');
  });

  it('defaults the status and priority selects to "all" when unset', () => {
    render(<TaskFilters value={{}} onChange={() => {}} />);
    expect(selects()[1].value).toBe('all');
    expect(selects()[2].value).toBe('all');
  });

  it('changing the scope select calls onChange with the merged next value', () => {
    const onChange = vi.fn();
    render(<TaskFilters value={{ scope: 'mine' }} onChange={onChange} />);
    fireEvent.change(selects()[0], { target: { value: 'created' } });
    expect(onChange).toHaveBeenCalledWith({ scope: 'created' });
  });

  it('changing the status select to a real status calls onChange with that status', () => {
    const onChange = vi.fn();
    const value: TaskFiltersValue = { scope: 'mine' };
    render(<TaskFilters value={value} onChange={onChange} />);
    fireEvent.change(selects()[1], { target: { value: 'todo' } });
    expect(onChange).toHaveBeenCalledWith({ scope: 'mine', status: 'todo' });
  });

  it('changing the status select back to "all" clears status to undefined', () => {
    const onChange = vi.fn();
    const value: TaskFiltersValue = { scope: 'mine', status: 'todo' };
    render(<TaskFilters value={value} onChange={onChange} />);
    fireEvent.change(selects()[1], { target: { value: 'all' } });
    expect(onChange).toHaveBeenCalledWith({ scope: 'mine', status: undefined });
  });

  it('changing the priority select to a real priority calls onChange with that priority', () => {
    const onChange = vi.fn();
    const value: TaskFiltersValue = { scope: 'mine' };
    render(<TaskFilters value={value} onChange={onChange} />);
    fireEvent.change(selects()[2], { target: { value: 'urgent' } });
    expect(onChange).toHaveBeenCalledWith({ scope: 'mine', priority: 'urgent' });
  });

  it('changing the priority select back to "all" clears priority to undefined', () => {
    const onChange = vi.fn();
    const value: TaskFiltersValue = { scope: 'mine', priority: 'urgent' };
    render(<TaskFilters value={value} onChange={onChange} />);
    fireEvent.change(selects()[2], { target: { value: 'all' } });
    expect(onChange).toHaveBeenCalledWith({ scope: 'mine', priority: undefined });
  });

  it('toggling the overdue switch calls onChange with overdue: true', () => {
    const onChange = vi.fn();
    const value: TaskFiltersValue = { scope: 'mine' };
    render(<TaskFilters value={value} onChange={onChange} />);
    fireEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith({ scope: 'mine', overdue: true });
  });

  it('toggling the overdue switch off calls onChange with overdue: false', () => {
    const onChange = vi.fn();
    const value: TaskFiltersValue = { scope: 'mine', overdue: true };
    render(<TaskFilters value={value} onChange={onChange} />);
    fireEvent.click(screen.getByRole('switch'));
    expect(onChange).toHaveBeenCalledWith({ scope: 'mine', overdue: false });
  });
});
