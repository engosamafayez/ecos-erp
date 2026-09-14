import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §32 — Voice UX in the unified
// inbox. Mirrors the exact mocking conventions already established by
// crm-customer-followup-tab.test.tsx (selector-mode t() -> dotted-path proxy, usePermission
// mock, hook-level mocking instead of a real QueryClientProvider).

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

let mockCan: (permission: string) => boolean = () => true;
vi.mock('@/features/authorization', () => ({ usePermission: () => ({ can: (p: string) => mockCan(p) }) }));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

const toastSpy = vi.fn();
vi.mock('@/components/ds', () => ({ useToast: () => ({ toast: toastSpy }) }));

// Radix Select needs pointer-capture/scroll APIs jsdom doesn't implement; this codebase's own
// convention (create-task-dialog.test.tsx) sidesteps that entirely with a native <select> stand-in.
import type { ReactNode } from 'react';
vi.mock('@/components/ui/select', () => ({
  Select: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) => (
    <select data-testid="select" value={value} onChange={(e) => onValueChange(e.target.value)}>{children}</select>
  ),
  SelectTrigger: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectValue: () => null,
  SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectItem: ({ value, children }: { value: string; children: ReactNode }) => <option value={value}>{children}</option>,
}));

import {
  useVoiceChannelProviders,
  useInitiateOutboundCall,
  useRequestHumanTransfer,
  useCallTranscript,
  useCallRecording,
} from '../hooks/use-voice';
vi.mock('../hooks/use-voice', () => ({
  useVoiceChannelProviders: vi.fn(),
  useInitiateOutboundCall: vi.fn(),
  useRequestHumanTransfer: vi.fn(),
  useCallTranscript: vi.fn(),
  useCallRecording: vi.fn(),
}));

import { VoiceCallBar, OutboundCallButton } from './unified-inbox-page';
import type { Call } from '../types/cep';

const mockProviders = useVoiceChannelProviders as unknown as ReturnType<typeof vi.fn>;
const mockInitiate = useInitiateOutboundCall as unknown as ReturnType<typeof vi.fn>;
const mockTransfer = useRequestHumanTransfer as unknown as ReturnType<typeof vi.fn>;
const mockTranscript = useCallTranscript as unknown as ReturnType<typeof vi.fn>;
const mockRecording = useCallRecording as unknown as ReturnType<typeof vi.fn>;

const BASE_CALL: Call = {
  id: 'call-1',
  conversation_id: 'conv-1',
  company_id: 'company-1',
  brand_id: null,
  customer_id: null,
  lead_id: null,
  direction: 'inbound',
  from_number: '201000000001',
  to_number: '201099999999',
  provider: 'voice',
  canonical_state: 'ai_active',
  canonical_state_label: 'AI Active',
  started_at: '2026-09-14T10:00:00Z',
  answered_at: '2026-09-14T10:00:01Z',
  ended_at: null,
  duration_seconds: 42,
  outcome: null,
  handled_by: 'ai',
  transferred_at: null,
  transfer_target_type: null,
  verification_level: 'unverified',
  has_transcript: false,
  has_recording: false,
  created_at: '2026-09-14T10:00:00Z',
};

const transferMutate = vi.fn();

function setup() {
  mockCan = () => true;
  mockProviders.mockReturnValue({ data: [] });
  mockInitiate.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
  mockTransfer.mockReturnValue({ mutateAsync: transferMutate, isPending: false });
  mockTranscript.mockReturnValue({ data: undefined });
  mockRecording.mockReturnValue({ data: undefined });
  transferMutate.mockReset();
  toastSpy.mockReset();
}

describe('VoiceCallBar', () => {
  beforeEach(setup);

  it('renders the canonical state, direction and handled-by badges from the Call itself, never a raw provider status', () => {
    render(<VoiceCallBar call={BASE_CALL} />);

    expect(screen.getByText('voice.callBar.state.ai_active')).toBeInTheDocument();
    expect(screen.getByText('voice.callBar.direction.inbound')).toBeInTheDocument();
    expect(screen.getByText('voice.callBar.handledBy.ai')).toBeInTheDocument();
  });

  it('shows the unverified caller badge for an unverified call', () => {
    render(<VoiceCallBar call={BASE_CALL} />);
    expect(screen.getByText('voice.callBar.verification.unverified')).toBeInTheDocument();
  });

  it('shows the verified badge once the call is order-corroborated', () => {
    render(<VoiceCallBar call={{ ...BASE_CALL, verification_level: 'order_corroborated' }} />);
    expect(screen.getByText('voice.callBar.verification.order_corroborated')).toBeInTheDocument();
  });

  it('offers the transfer button for an active call when cep.voice.transfer is granted', () => {
    render(<VoiceCallBar call={BASE_CALL} />);
    expect(screen.getByText('voice.callBar.transferButton')).toBeInTheDocument();
  });

  it('hides the transfer button without cep.voice.transfer', () => {
    mockCan = (p) => p !== 'cep.voice.transfer';
    render(<VoiceCallBar call={BASE_CALL} />);
    expect(screen.queryByText('voice.callBar.transferButton')).not.toBeInTheDocument();
  });

  it('hides the transfer button once the call has reached a terminal canonical state', () => {
    render(<VoiceCallBar call={{ ...BASE_CALL, canonical_state: 'completed' }} />);
    expect(screen.queryByText('voice.callBar.transferButton')).not.toBeInTheDocument();
  });

  it('hides the transfer button while the call is already human_active or transferring', () => {
    const { rerender } = render(<VoiceCallBar call={{ ...BASE_CALL, canonical_state: 'human_active' }} />);
    expect(screen.queryByText('voice.callBar.transferButton')).not.toBeInTheDocument();
    rerender(<VoiceCallBar call={{ ...BASE_CALL, canonical_state: 'transferring' }} />);
    expect(screen.queryByText('voice.callBar.transferButton')).not.toBeInTheDocument();
  });

  it('requests a transfer with the typed reason and surfaces a success toast on a bridged outcome', async () => {
    transferMutate.mockResolvedValue({ result: 'bridged', task_id: null, failure_reason: null, call: BASE_CALL });
    render(<VoiceCallBar call={BASE_CALL} />);

    fireEvent.click(screen.getByText('voice.callBar.transferButton'));
    fireEvent.change(screen.getByPlaceholderText('voice.callBar.transferReasonPlaceholder'), { target: { value: 'Customer asked for a manager' } });
    fireEvent.click(screen.getByText('voice.callBar.transferSubmit'));

    await waitFor(() => expect(transferMutate).toHaveBeenCalledWith({ reason: 'Customer asked for a manager' }));
    await waitFor(() => expect(toastSpy).toHaveBeenCalledWith(
      // Asserts against the mocked t()'s dotted-path output, not real UI copy.
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings
      expect.objectContaining({ title: 'voice.callBar.transferResult.bridged', type: 'success' }),
    ));
  });

  it('surfaces a warning toast, never a success one, when the transfer could not be bridged', async () => {
    transferMutate.mockResolvedValue({ result: 'transfer_unavailable', task_id: 'task-9', failure_reason: 'no agent', call: BASE_CALL });
    render(<VoiceCallBar call={BASE_CALL} />);

    fireEvent.click(screen.getByText('voice.callBar.transferButton'));
    fireEvent.click(screen.getByText('voice.callBar.transferSubmit'));

    await waitFor(() => expect(toastSpy).toHaveBeenCalledWith(
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings
      expect.objectContaining({ title: 'voice.callBar.transferResult.transfer_unavailable', type: 'warning' }),
    ));
  });

  it('never offers to view transcript/recording refs without cep.voice.recordings.view, even when a transcript exists', () => {
    mockCan = (p) => p !== 'cep.voice.recordings.view';
    render(<VoiceCallBar call={{ ...BASE_CALL, has_transcript: true }} />);

    expect(screen.queryByText('voice.callBar.viewTranscriptRef')).not.toBeInTheDocument();
    expect(screen.getByText('voice.callBar.recordingsPermissionMissing')).toBeInTheDocument();
  });

  it('offers to view the transcript reference when has_transcript and the permission is granted', () => {
    render(<VoiceCallBar call={{ ...BASE_CALL, has_transcript: true }} />);
    expect(screen.getByText('voice.callBar.viewTranscriptRef')).toBeInTheDocument();
  });

  it('renders no transcript/recording action at all when the call has neither', () => {
    render(<VoiceCallBar call={BASE_CALL} />);
    expect(screen.queryByText('voice.callBar.viewTranscriptRef')).not.toBeInTheDocument();
    expect(screen.queryByText('voice.callBar.viewRecordingRef')).not.toBeInTheDocument();
    expect(screen.queryByText('voice.callBar.recordingsPermissionMissing')).not.toBeInTheDocument();
  });
});

describe('OutboundCallButton', () => {
  beforeEach(setup);

  it('renders nothing at all without cep.voice.use — hidden, not merely disabled', () => {
    mockCan = () => false;
    const { container } = render(<OutboundCallButton />);
    expect(container).toBeEmptyDOMElement();
  });

  it('renders the call trigger once cep.voice.use is granted', () => {
    render(<OutboundCallButton />);
    expect(screen.getByText('voice.outboundDialog.trigger')).toBeInTheDocument();
  });

  it('shows an honest "no providers configured" message rather than a fabricated calling identity', () => {
    mockProviders.mockReturnValue({ data: [] });
    render(<OutboundCallButton />);
    fireEvent.click(screen.getByText('voice.outboundDialog.trigger'));
    expect(screen.getByText('voice.outboundDialog.noProviders')).toBeInTheDocument();
  });

  it('initiates the call with the typed destination and selected purpose against the chosen Brand identity', async () => {
    mockProviders.mockReturnValue({
      data: [{ id: 'prov-1', company_id: 'company-1', brand_id: null, channel: 'voice', display_name: 'Main Line', phone_number: '201099999999', status: 'active' }],
    });
    const initiateMutate = vi.fn().mockResolvedValue({ ...BASE_CALL });
    mockInitiate.mockReturnValue({ mutateAsync: initiateMutate, isPending: false });

    render(<OutboundCallButton />);
    fireEvent.click(screen.getByText('voice.outboundDialog.trigger'));
    fireEvent.change(screen.getByPlaceholderText('voice.outboundDialog.destinationPlaceholder'), { target: { value: '01055512345' } });
    fireEvent.change(screen.getAllByTestId('select')[0], { target: { value: 'prov-1' } });
    fireEvent.click(screen.getByText('voice.outboundDialog.submit'));

    await waitFor(() => expect(initiateMutate).toHaveBeenCalledWith({ to_number: '01055512345', purpose: 'support' }));
    await waitFor(() => expect(toastSpy).toHaveBeenCalledWith(
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- dotted-path output of the mocked t(), not real UI copy
      expect.objectContaining({ title: 'voice.outboundDialog.success', type: 'success' }),
    ));
  });
});
