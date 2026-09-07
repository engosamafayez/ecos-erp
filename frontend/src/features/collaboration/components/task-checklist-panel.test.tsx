import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

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

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

const mocks = vi.hoisted(() => ({
  useTaskChecklists: vi.fn(),
  useCreateTaskChecklist: vi.fn(),
  useAddTaskChecklistItem: vi.fn(),
  useUpdateTaskChecklistItem: vi.fn(),
  useDeleteTaskChecklistItem: vi.fn(),
}));
vi.mock('../hooks/use-tasks', () => ({
  useTaskChecklists: () => mocks.useTaskChecklists(),
  useCreateTaskChecklist: () => mocks.useCreateTaskChecklist(),
  useAddTaskChecklistItem: () => mocks.useAddTaskChecklistItem(),
  useUpdateTaskChecklistItem: () => mocks.useUpdateTaskChecklistItem(),
  useDeleteTaskChecklistItem: () => mocks.useDeleteTaskChecklistItem(),
}));

import { TaskChecklistPanel } from './task-checklist-panel';

const CHECKLIST = {
  id: 'cl1',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a TaskChecklist.title field, not UI copy
  title: 'Steps',
  position: 0,
  items: [
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a TaskChecklistItem.title field, not UI copy
    { id: 'i1', title: 'First', is_completed: true, position: 0 },
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a TaskChecklistItem.title field, not UI copy
    { id: 'i2', title: 'Second', is_completed: false, position: 1 },
  ],
};

let addItemMutate: ReturnType<typeof vi.fn>;
let updateItemMutate: ReturnType<typeof vi.fn>;
let deleteItemMutate: ReturnType<typeof vi.fn>;
let createChecklistMutate: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  mocks.useTaskChecklists.mockReturnValue({ data: [CHECKLIST], isLoading: false });
  addItemMutate = vi.fn();
  mocks.useAddTaskChecklistItem.mockReturnValue({ mutate: addItemMutate, isPending: false });
  updateItemMutate = vi.fn();
  mocks.useUpdateTaskChecklistItem.mockReturnValue({ mutate: updateItemMutate, isPending: false });
  deleteItemMutate = vi.fn();
  mocks.useDeleteTaskChecklistItem.mockReturnValue({ mutate: deleteItemMutate, isPending: false });
  createChecklistMutate = vi.fn();
  mocks.useCreateTaskChecklist.mockReturnValue({ mutate: createChecklistMutate, isPending: false });
});

describe('TaskChecklistPanel', () => {
  it('shows progress (completed/total) matching the item states', () => {
    render(<TaskChecklistPanel taskId="t1" />);
    expect(screen.getByText('tasks.checklist.progress')).toBeInTheDocument();
  });

  it('renders each item with its completed state reflected in the checkbox', () => {
    render(<TaskChecklistPanel taskId="t1" />);
    const checkboxes = screen.getAllByRole('checkbox') as HTMLInputElement[];
    expect(checkboxes[0].checked).toBe(true);
    expect(checkboxes[1].checked).toBe(false);
  });

  it('toggles completion through the update mutation when a checkbox is clicked', () => {
    render(<TaskChecklistPanel taskId="t1" />);
    fireEvent.click(screen.getAllByRole('checkbox')[1]);
    expect(updateItemMutate).toHaveBeenCalledWith(
      { checklistId: 'cl1', itemId: 'i2', changes: { is_completed: true } },
      expect.anything(),
    );
  });

  it('deletes an item through the delete mutation', () => {
    render(<TaskChecklistPanel taskId="t1" />);
    fireEvent.click(screen.getAllByLabelText('tasks.checklist.delete')[0]);
    expect(deleteItemMutate).toHaveBeenCalledWith({ checklistId: 'cl1', itemId: 'i1' }, expect.anything());
  });

  it('adds a new item to the checklist via the inline form', () => {
    render(<TaskChecklistPanel taskId="t1" />);
    const input = screen.getByPlaceholderText('tasks.checklist.itemPlaceholder');
    fireEvent.change(input, { target: { value: 'Third' } });
    fireEvent.submit(input);
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value asserted in a mutation call, not UI copy
    expect(addItemMutate).toHaveBeenCalledWith({ checklistId: 'cl1', title: 'Third' }, expect.anything());
  });

  it('creates a new checklist via the top-level form', () => {
    mocks.useTaskChecklists.mockReturnValue({ data: [], isLoading: false });
    render(<TaskChecklistPanel taskId="t1" />);
    const input = screen.getByPlaceholderText('tasks.checklist.titlePlaceholder');
    fireEvent.change(input, { target: { value: 'New checklist' } });
    fireEvent.submit(input);
    expect(createChecklistMutate).toHaveBeenCalledWith('New checklist', expect.anything());
  });

  it('shows the empty-checklist note when a checklist has no items', () => {
    mocks.useTaskChecklists.mockReturnValue({ data: [{ ...CHECKLIST, items: [] }], isLoading: false });
    render(<TaskChecklistPanel taskId="t1" />);
    expect(screen.getByText('tasks.checklist.empty')).toBeInTheDocument();
  });
});
