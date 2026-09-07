import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

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

// jsdom lacks the pointer-capture APIs Radix Select needs (used for the context-link type picker).
vi.mock('@/components/ui/select', () => ({
  Select: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) => (
    <select data-testid="select" value={value} onChange={(e) => onValueChange(e.target.value)}>{children}</select>
  ),
  SelectTrigger: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectValue: () => null,
  SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectItem: ({ value, children }: { value: string; children: ReactNode }) => <option value={value}>{children}</option>,
}));

// Radix Tabs' roving-tabindex + pointer-capture triggers don't reliably activate under
// fireEvent.click in jsdom (see driver-settlement-workspace-page.test.tsx). Swap in a minimal
// equivalent; TabsContent renders unconditionally (all four tabs mount together) so each tab's
// content is asserted directly via its data-tab-content scope rather than by driving a click.
vi.mock('@/components/ui/tabs', async () => {
  const React = await import('react');
  const Ctx = React.createContext<(v: string) => void>(() => {});
  return {
    Tabs: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) =>
      React.createElement(Ctx.Provider, { value: onValueChange }, React.createElement('div', { 'data-value': value }, children)),
    TabsList: ({ children }: { children: ReactNode }) => React.createElement('div', { role: 'tablist' }, children),
    TabsTrigger: ({ value, children }: { value: string; children: ReactNode }) => {
      const onValueChange = React.useContext(Ctx);
      return React.createElement('button', { type: 'button', role: 'tab', onClick: () => onValueChange(value) }, children);
    },
    TabsContent: ({ value, children, ...props }: { value: string; children: ReactNode; className?: string }) =>
      React.createElement('div', { 'data-tab-content': value, ...props }, children),
  };
});

// task-detail-drawer imports UserPicker from the sibling './user-picker'.
vi.mock('./user-picker', () => ({
  UserPicker: ({ onChange }: { onChange: (user: AddressableUser | null) => void }) => (
    <button type="button" onClick={() => onChange({ id: 42, name: 'Picked User', is_driver: false })}>pick-user</button>
  ),
}));

// The label/followers/checklist panels each get their own dedicated test file —
// here they're black boxes, same posture as UserPicker above, so this file stays
// focused on TaskDetailDrawer's own behavior (label row + remove, tab presence).
vi.mock('./task-label-picker', () => ({
  TaskLabelPicker: () => <button type="button">add-label</button>,
}));
vi.mock('./task-followers-panel', () => ({
  TaskFollowersPanel: () => <div data-testid="followers-panel" />,
}));
vi.mock('./task-checklist-panel', () => ({
  TaskChecklistPanel: () => <div data-testid="checklist-panel" />,
}));

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

type MockAuthState = { user: { id: number; name: string } | null };
const authState = vi.hoisted(() => ({ userId: 1 as number | undefined }));
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (state: MockAuthState) => unknown) =>
    selector({ user: authState.userId === undefined ? null : { id: authState.userId, name: 'Current User' } }),
}));

const mocks = vi.hoisted(() => ({
  useTask: vi.fn(),
  useTransitionTaskStatus: vi.fn(),
  useReassignTask: vi.fn(),
  useDetachTaskLabel: vi.fn(),
  useTaskComments: vi.fn(),
  useAddTaskComment: vi.fn(),
  useTaskAttachments: vi.fn(),
  useAddTaskAttachment: vi.fn(),
  useTaskContextLinks: vi.fn(),
  useAttachTaskContext: vi.fn(),
  downloadTaskAttachment: vi.fn(),
}));

vi.mock('../hooks/use-tasks', () => ({
  useTask: (id: string | null) => mocks.useTask(id),
  useTransitionTaskStatus: (id: string) => mocks.useTransitionTaskStatus(id),
  useReassignTask: (id: string) => mocks.useReassignTask(id),
  useDetachTaskLabel: (id: string) => mocks.useDetachTaskLabel(id),
  useTaskComments: (id: string) => mocks.useTaskComments(id),
  useAddTaskComment: (id: string) => mocks.useAddTaskComment(id),
  useTaskAttachments: (id: string) => mocks.useTaskAttachments(id),
  useAddTaskAttachment: (id: string) => mocks.useAddTaskAttachment(id),
  useTaskContextLinks: (id: string) => mocks.useTaskContextLinks(id),
  useAttachTaskContext: (id: string) => mocks.useAttachTaskContext(id),
}));

vi.mock('../hooks/use-secure-media', () => ({
  downloadTaskAttachment: (...args: unknown[]) => mocks.downloadTaskAttachment(...args),
}));

import { TaskDetailDrawer } from './task-detail-drawer';
import type { AddressableUser, Task } from '../types';

function makeTask(overrides: Partial<Task> = {}): Task {
  return {
    id: 't1',
    company_id: 'c1',
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
    title: 'Investigate delayed shipment',
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.description field, not UI copy
    description: 'Check with the carrier about the delay.',
    creator_user_id: 1,
    creator_name: 'Creator One',
    assignee_user_id: 2,
    assignee_name: 'Assignee Two',
    team_id: null,
    priority: 'normal',
    status: 'todo',
    due_at: null,
    is_overdue: false,
    completed_at: null,
    cancelled_at: null,
    source_conversation_id: null,
    source_message_id: null,
    source_message_snapshot: null,
    activity: [],
    created_at: '2026-08-01T00:00:00Z',
    updated_at: '2026-08-01T00:00:00Z',
    ...overrides,
  };
}

function loadTask(task: Task) {
  mocks.useTask.mockReturnValue({ data: task, isLoading: false, isError: false });
}

function overviewRegion(): HTMLElement {
  return document.querySelector('[data-tab-content="overview"]') as HTMLElement;
}
function commentsRegion(): HTMLElement {
  return document.querySelector('[data-tab-content="comments"]') as HTMLElement;
}
function attachmentsRegion(): HTMLElement {
  return document.querySelector('[data-tab-content="attachments"]') as HTMLElement;
}
function activityRegion(): HTMLElement {
  return document.querySelector('[data-tab-content="activity"]') as HTMLElement;
}
function sheetHeader(): HTMLElement {
  return document.querySelector('[data-slot="sheet-header"]') as HTMLElement;
}

const onViewSourceConversation = vi.fn();
const onOpenChange = vi.fn();

function renderDrawer(taskId: string | null) {
  return render(
    <TaskDetailDrawer
      taskId={taskId}
      open={true}
      onOpenChange={onOpenChange}
      onViewSourceConversation={onViewSourceConversation}
    />,
  );
}

let transitionMutate: ReturnType<typeof vi.fn>;
let reassignMutate: ReturnType<typeof vi.fn>;
let detachLabelMutate: ReturnType<typeof vi.fn>;
let addCommentMutate: ReturnType<typeof vi.fn>;
let addAttachmentMutate: ReturnType<typeof vi.fn>;
let attachContextMutate: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  authState.userId = 1;

  mocks.useTask.mockReturnValue({ data: undefined, isLoading: true, isError: false });

  transitionMutate = vi.fn();
  mocks.useTransitionTaskStatus.mockReturnValue({ mutate: transitionMutate, isPending: false });

  reassignMutate = vi.fn();
  mocks.useReassignTask.mockReturnValue({ mutate: reassignMutate, isPending: false });

  detachLabelMutate = vi.fn();
  mocks.useDetachTaskLabel.mockReturnValue({ mutate: detachLabelMutate, isPending: false });

  mocks.useTaskComments.mockReturnValue({ data: [], isLoading: false });
  addCommentMutate = vi.fn();
  mocks.useAddTaskComment.mockReturnValue({ mutate: addCommentMutate, isPending: false });

  mocks.useTaskAttachments.mockReturnValue({ data: [], isLoading: false });
  addAttachmentMutate = vi.fn();
  mocks.useAddTaskAttachment.mockReturnValue({ mutate: addAttachmentMutate, isPending: false });

  mocks.useTaskContextLinks.mockReturnValue({ data: [] });
  attachContextMutate = vi.fn();
  mocks.useAttachTaskContext.mockReturnValue({ mutate: attachContextMutate, isPending: false });

  mocks.downloadTaskAttachment.mockResolvedValue(undefined);
});

describe('TaskDetailDrawer', () => {
  it('renders nothing when taskId is null', () => {
    const { container } = renderDrawer(null);
    expect(container).toBeEmptyDOMElement();
  });

  it('shows a loading state while the task is loading', () => {
    mocks.useTask.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    renderDrawer('t1');
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('shows an error state when loading the task fails', () => {
    mocks.useTask.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    renderDrawer('t1');
    expect(screen.getByText('tasks.detail.loadFailed')).toBeInTheDocument();
  });

  // ── (a) Source message privacy ────────────────────────────────────────────
  describe('source message privacy', () => {
    it('renders "source unavailable" and no snapshot/link when the snapshot is hidden from this viewer', () => {
      loadTask(makeTask({ source_message_id: 'm1', source_message_snapshot: null }));
      renderDrawer('t1');
      const overview = overviewRegion();
      expect(within(overview).getByText('tasks.detail.sourceUnavailable')).toBeInTheDocument();
      expect(within(overview).queryByText('tasks.detail.viewSource')).not.toBeInTheDocument();
    });

    it('renders the snapshot text and a working "view in conversation" control when the snapshot is present', () => {
      const task = makeTask({
        source_message_id: 'm1',
        source_message_snapshot: 'the actual text',
        source_conversation_id: 'conv-1',
      });
      loadTask(task);
      renderDrawer('t1');
      const overview = overviewRegion();
      expect(within(overview).getByText('the actual text')).toBeInTheDocument();
      expect(within(overview).queryByText('tasks.detail.sourceUnavailable')).not.toBeInTheDocument();

      fireEvent.click(within(overview).getByText('tasks.detail.viewSource'));
      expect(onViewSourceConversation).toHaveBeenCalledWith('conv-1');
    });

    it('renders no "started from a message" block at all when there is no source message', () => {
      loadTask(makeTask({ source_message_id: null, source_message_snapshot: null }));
      renderDrawer('t1');
      const overview = overviewRegion();
      expect(within(overview).queryByText('tasks.detail.sourceMessage')).not.toBeInTheDocument();
      expect(within(overview).queryByText('tasks.detail.sourceUnavailable')).not.toBeInTheDocument();
    });
  });

  // ── (b) Status transitions gated by role ───────────────────────────────────
  describe('status transitions', () => {
    it('offers both allowed transitions from todo to the assignee, sending the correct target status on click', () => {
      authState.userId = 2; // assignee
      loadTask(makeTask({ status: 'todo', creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');

      const buttons = within(sheetHeader()).getAllByRole('button');
      expect(buttons).toHaveLength(2); // todo -> [in_progress, cancelled]

      fireEvent.click(buttons[0]);
      expect(transitionMutate).toHaveBeenNthCalledWith(1, 'in_progress', expect.anything());
      fireEvent.click(buttons[1]);
      expect(transitionMutate).toHaveBeenNthCalledWith(2, 'cancelled', expect.anything());
    });

    it('also offers transitions to the creator (not just the assignee)', () => {
      authState.userId = 1; // creator
      loadTask(makeTask({ status: 'todo', creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');
      expect(within(sheetHeader()).getAllByRole('button')).toHaveLength(2);
    });

    it('offers no transition buttons to a viewer who is neither creator nor assignee', () => {
      authState.userId = 999;
      loadTask(makeTask({ status: 'todo', creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');
      expect(within(sheetHeader()).queryAllByRole('button')).toHaveLength(0);
    });

    it('offers exactly the reopen transition (done -> in_progress) from a done task', () => {
      authState.userId = 2; // assignee
      loadTask(makeTask({ status: 'done', creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');

      const buttons = within(sheetHeader()).getAllByRole('button');
      expect(buttons).toHaveLength(1);
      fireEvent.click(buttons[0]);
      expect(transitionMutate).toHaveBeenCalledWith('in_progress', expect.anything());
    });

    it('offers both allowed transitions from in_progress, sending the correct target status on click', () => {
      authState.userId = 2;
      loadTask(makeTask({ status: 'in_progress', creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');

      const buttons = within(sheetHeader()).getAllByRole('button');
      expect(buttons).toHaveLength(2); // in_progress -> [done, cancelled]
      fireEvent.click(buttons[0]);
      expect(transitionMutate).toHaveBeenNthCalledWith(1, 'done', expect.anything());
      fireEvent.click(buttons[1]);
      expect(transitionMutate).toHaveBeenNthCalledWith(2, 'cancelled', expect.anything());
    });

    it('offers no transitions from a terminal cancelled task, even to the creator', () => {
      authState.userId = 1; // creator
      loadTask(makeTask({ status: 'cancelled', creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');
      expect(within(sheetHeader()).queryAllByRole('button')).toHaveLength(0);
    });
  });

  // ── (c) Reassign gated to creator only ─────────────────────────────────────
  describe('reassign', () => {
    it('shows a Reassign control to the creator, and picking a user reassigns the task', () => {
      authState.userId = 1; // creator
      loadTask(makeTask({ creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');

      const overview = overviewRegion();
      fireEvent.click(within(overview).getByText('tasks.detail.reassign'));
      fireEvent.click(within(overview).getByText('pick-user'));

      expect(reassignMutate).toHaveBeenCalledWith(42, expect.anything());
    });

    it('shows no Reassign control to the assignee (non-creator)', () => {
      authState.userId = 2; // assignee, not creator
      loadTask(makeTask({ creator_user_id: 1, assignee_user_id: 2 }));
      renderDrawer('t1');
      expect(within(overviewRegion()).queryByText('tasks.detail.reassign')).not.toBeInTheDocument();
    });
  });

  // ── (g) Labels ───────────────────────────────────────────────────────────────
  describe('labels', () => {
    it('renders a badge per attached label and removes it via the label mutation on click', () => {
      loadTask(makeTask({ labels: [{ id: 'l1', name: 'Urgent', color: 'red' }] }));
      renderDrawer('t1');
      const overview = overviewRegion();
      expect(within(overview).getByText('Urgent')).toBeInTheDocument();

      fireEvent.click(within(overview).getByLabelText('Urgent'));
      expect(detachLabelMutate).toHaveBeenCalledWith('l1', expect.anything());
    });

    it('always renders the add-label control', () => {
      loadTask(makeTask({ labels: [] }));
      renderDrawer('t1');
      expect(within(overviewRegion()).getByText('add-label')).toBeInTheDocument();
    });
  });

  // ── (h) Followers panel ─────────────────────────────────────────────────────
  it('renders the followers panel in the overview tab', () => {
    loadTask(makeTask());
    renderDrawer('t1');
    expect(within(overviewRegion()).getByTestId('followers-panel')).toBeInTheDocument();
  });

  // ── (i) Checklist tab ───────────────────────────────────────────────────────
  it('renders a checklist tab that mounts the checklist panel', () => {
    loadTask(makeTask());
    renderDrawer('t1');
    expect(screen.getByText('tasks.detail.checklists')).toBeInTheDocument();
    expect(document.querySelector('[data-tab-content="checklist"]')).not.toBeNull();
    expect(within(document.querySelector('[data-tab-content="checklist"]') as HTMLElement).getByTestId('checklist-panel')).toBeInTheDocument();
  });

  // ── (d) Comments tab ────────────────────────────────────────────────────────
  describe('comments tab', () => {
    it('renders existing comments with author names', () => {
      mocks.useTaskComments.mockReturnValue({
        data: [{ id: 'c1', task_id: 't1', author_user_id: 2, author_name: 'Alice', body: 'Looking into it now.', created_at: '2026-08-01T00:00:00Z' }],
        isLoading: false,
      });
      loadTask(makeTask());
      renderDrawer('t1');
      const region = commentsRegion();
      expect(within(region).getByText('Alice')).toBeInTheDocument();
      expect(within(region).getByText('Looking into it now.')).toBeInTheDocument();
    });

    it('disables Post until there is text, posts the trimmed body, and clears the textarea on success', () => {
      loadTask(makeTask());
      renderDrawer('t1');
      const region = commentsRegion();
      const textarea = within(region).getByPlaceholderText('tasks.detail.addCommentPlaceholder') as HTMLTextAreaElement;
      const postButton = within(region).getByText('tasks.detail.postComment').closest('button')!;
      expect(postButton).toBeDisabled();

      fireEvent.change(textarea, { target: { value: '  Please review this  ' } });
      expect(postButton).not.toBeDisabled();

      fireEvent.click(postButton);
      expect(addCommentMutate).toHaveBeenCalledWith('Please review this', expect.objectContaining({ onSuccess: expect.any(Function) }));

      const onSuccess = addCommentMutate.mock.calls[0][1].onSuccess as () => void;
      act(() => onSuccess());
      expect(textarea.value).toBe('');
    });
  });

  // ── (e) Attachments tab ─────────────────────────────────────────────────────
  describe('attachments tab', () => {
    it('renders existing attachments and downloads one on click', () => {
      mocks.useTaskAttachments.mockReturnValue({
        data: [{ id: 'a1', name: 'invoice.pdf', mime_type: 'application/pdf', file_size: 1024, uploaded_by: 2, created_at: '2026-08-01T00:00:00Z' }],
        isLoading: false,
      });
      loadTask(makeTask());
      renderDrawer('t1');
      const region = attachmentsRegion();
      expect(within(region).getByText('invoice.pdf')).toBeInTheDocument();

      const downloadButtons = within(region).getAllByRole('button');
      expect(downloadButtons).toHaveLength(1);
      fireEvent.click(downloadButtons[0]);
      expect(mocks.downloadTaskAttachment).toHaveBeenCalledWith('t1', 'a1', 'invoice.pdf');
    });

    it('uploads a chosen file through the upload mutation', () => {
      loadTask(makeTask());
      renderDrawer('t1');
      const region = attachmentsRegion();
      const fileInput = region.querySelector('input[type="file"]') as HTMLInputElement;
      expect(fileInput).toBeInTheDocument();

      const file = new File(['dummy'], 'photo.png', { type: 'image/png' });
      fireEvent.change(fileInput, { target: { files: [file] } });

      expect(addAttachmentMutate).toHaveBeenCalledWith(file, expect.anything());
    });
  });

  // ── (f) Activity tab ─────────────────────────────────────────────────────────
  describe('activity tab', () => {
    it('renders each entry with the actor name and a translated event label, falling back to the raw event_type when unrecognized', () => {
      loadTask(
        makeTask({
          activity: [
            { id: 'e1', actor_user_id: 2, actor_name: 'Alice', event_type: 'created', from_value: null, to_value: null, created_at: '2026-08-01T00:00:00Z' },
            { id: 'e2', actor_user_id: 3, actor_name: 'Bob', event_type: 'some_future_event', from_value: null, to_value: 'x', created_at: '2026-08-02T00:00:00Z' },
          ],
        }),
      );
      renderDrawer('t1');
      const region = activityRegion();

      expect(within(region).getByText('Alice')).toBeInTheDocument();
      expect(within(region).getByText('tasks.activityEvents.created')).toBeInTheDocument();

      expect(within(region).getByText('Bob')).toBeInTheDocument();
      // Unknown event_type falls back to the raw string rather than crashing or going blank.
      expect(within(region).getByText('some_future_event')).toBeInTheDocument();
      expect(within(region).queryByText('tasks.activityEvents.some_future_event')).not.toBeInTheDocument();

      expect(within(region).getAllByRole('listitem')).toHaveLength(2);
    });

    it('shows the empty-activity note when there are no activity entries', () => {
      loadTask(makeTask({ activity: [] }));
      renderDrawer('t1');
      expect(within(activityRegion()).getByText('tasks.detail.noActivity')).toBeInTheDocument();
    });
  });
});
