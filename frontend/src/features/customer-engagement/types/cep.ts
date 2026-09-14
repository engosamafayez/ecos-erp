// Customer Engagement Platform — Type System

// ─── Enums ────────────────────────────────────────────────────────────────────

export type ConversationStatus =
  | 'open' | 'pending' | 'waiting_customer' | 'waiting_agent' | 'resolved' | 'closed';

export type ConversationPriority = 'low' | 'medium' | 'high' | 'urgent';

export type CommunicationProvider =
  | 'whatsapp' | 'messenger' | 'instagram' | 'email' | 'live_chat' | 'telegram' | 'sms' | 'voice';

export type MessageDirection = 'inbound' | 'outbound';

export type MessageType =
  | 'text' | 'image' | 'video' | 'audio' | 'document' | 'template' | 'location' | 'sticker' | 'system';

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'unqualified' | 'converted' | 'lost';

export type AssignmentType =
  | 'manual' | 'round_robin' | 'department' | 'language' | 'brand' | 'channel' | 'campaign' | 'ai_routing';

// ─── Labels ───────────────────────────────────────────────────────────────────

export const CONVERSATION_STATUS_LABELS: Record<ConversationStatus, string> = {
  open:             'Open',
  pending:          'Pending',
  waiting_customer: 'Waiting Customer',
  waiting_agent:    'Waiting Agent',
  resolved:         'Resolved',
  closed:           'Closed',
};

export const PRIORITY_LABELS: Record<ConversationPriority, string> = {
  low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent',
};

export const PROVIDER_LABELS: Record<CommunicationProvider, string> = {
  whatsapp:  'WhatsApp',
  messenger: 'Facebook Messenger',
  instagram: 'Instagram Direct',
  email:     'Email',
  live_chat: 'Live Chat',
  telegram:  'Telegram',
  sms:       'SMS',
  voice:     'Voice',
};

export const LEAD_STATUS_LABELS: Record<LeadStatus, string> = {
  new: 'New', contacted: 'Contacted', qualified: 'Qualified',
  unqualified: 'Unqualified', converted: 'Converted', lost: 'Lost',
};

// ─── Provider Colors (Tailwind bg + text) ─────────────────────────────────────

export const PROVIDER_COLORS: Record<CommunicationProvider, string> = {
  whatsapp:  'bg-green-100 text-green-800',
  messenger: 'bg-blue-100 text-blue-800',
  instagram: 'bg-pink-100 text-pink-800',
  email:     'bg-gray-100 text-gray-700',
  live_chat: 'bg-indigo-100 text-indigo-800',
  telegram:  'bg-sky-100 text-sky-800',
  sms:       'bg-yellow-100 text-yellow-800',
  voice:     'bg-purple-100 text-purple-800',
};

export const STATUS_COLORS: Record<ConversationStatus, string> = {
  open:             'bg-green-100 text-green-800',
  pending:          'bg-yellow-100 text-yellow-800',
  waiting_customer: 'bg-orange-100 text-orange-800',
  waiting_agent:    'bg-blue-100 text-blue-800',
  resolved:         'bg-gray-100 text-gray-600',
  closed:           'bg-red-100 text-red-700',
};

// ─── Models ───────────────────────────────────────────────────────────────────

export interface Message {
  id: string;
  conversation_id: string;
  external_message_id: string | null;
  direction: MessageDirection;
  message_type: MessageType;
  content: string | null;
  media_url: string | null;
  media_type: string | null;
  media_size: number | null;
  sender_type: 'customer' | 'agent' | 'system';
  sender_id: string | null;
  sender_name: string | null;
  is_read: boolean;
  is_deleted: boolean;
  sent_at: string;
  delivered_at: string | null;
  read_at: string | null;
  created_at: string;
}

export interface SlaViolation {
  id: string;
  conversation_id: string;
  sla_policy_id: string;
  violation_type: 'first_response' | 'resolution';
  status: 'pending' | 'breached' | 'resolved';
  is_breached: boolean;
  due_at: string;
  breached_at: string | null;
  resolved_at: string | null;
}

export interface Lead {
  id: string;
  conversation_id: string | null;
  business_dna_id: string | null;
  company_id: string | null;
  brand_id: string | null;
  channel_id: string | null;
  customer_name: string;
  customer_phone: string | null;
  customer_email: string | null;
  status: LeadStatus;
  status_label: string;
  priority: ConversationPriority;
  score: number | null;
  assigned_to: string | null;
  source: string | null;
  qualification_notes: string | null;
  converted_entity_type: string | null;
  converted_entity_id: string | null;
  tags: string[];
  qualified_at: string | null;
  converted_at: string | null;
  lost_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface AssignmentLog {
  id: string;
  conversation_id: string;
  assignee_type: 'agent' | 'team';
  assignee_id: string;
  assigned_by: string | null;
  assignment_type: AssignmentType;
  notes: string | null;
  unassigned_at: string | null;
  created_at: string;
}

export interface PrivateNote {
  id: string;
  conversation_id: string;
  author_id: string;
  author_type: string;
  content: string;
  mentioned_user_ids: string[];
  created_at: string;
  updated_at: string;
}

export interface Conversation {
  id: string;
  conversation_uuid: string;
  provider: CommunicationProvider;
  provider_label: string;
  external_conversation_id: string | null;
  customer_id: string | null;
  customer_name: string | null;
  customer_phone: string | null;
  customer_email: string | null;
  business_dna_id: string | null;
  company_id: string | null;
  brand_id: string | null;
  channel_id: string | null;
  initiative_id: string | null;
  campaign_id: string | null;
  assigned_team_id: string | null;
  assigned_employee_id: string | null;
  sla_policy_id: string | null;
  status: ConversationStatus;
  status_label: string;
  priority: ConversationPriority;
  priority_label: string;
  source: string | null;
  language: string | null;
  tags: string[];
  messages_count: number;
  unread_count: number;
  internal_notes_count: number;
  first_response_at: string | null;
  last_message_at: string | null;
  started_at: string;
  closed_at: string | null;
  created_at: string;
  updated_at: string;
  messages?: Message[];
  sla_violations?: SlaViolation[];
  lead?: Lead | null;
  /** Only present for provider="voice" conversations — a Call is never a fake Message. */
  calls?: Call[];
}

export interface SlaPolicy {
  id: string;
  company_id: string | null;
  name: string;
  first_response_minutes: number;
  resolution_minutes: number;
  business_hours_only: boolean;
  is_default: boolean;
}

export interface DashboardKpis {
  conversations: {
    total: number;
    open: number;
    pending: number;
    resolved: number;
    unread: number;
    resolvedToday: number;
    avgFirstResponse: number | null;
  };
  sla: {
    total: number;
    breached: number;
    resolved: number;
    pending: number;
    rate: number;
  };
  leads: {
    total: number;
    new: number;
    qualified: number;
    converted: number;
  };
}

// ─── Voice ────────────────────────────────────────────────────────────────────
// TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 — Voice is a channel within this
// same CustomerEngagement platform (a Call always belongs to a provider="voice" Conversation),
// never a parallel Voice CRM. These types mirror the backend CallResource exactly.

export type CallDirection = 'inbound' | 'outbound';

export type CallCanonicalState =
  | 'initiated' | 'ringing' | 'connected' | 'ai_active' | 'transferring'
  | 'human_active' | 'completed' | 'failed' | 'no_answer' | 'busy' | 'cancelled';

export type CallHandledBy = 'ai' | 'human' | 'both';

export type CallerVerificationLevel = 'unverified' | 'order_corroborated';

export type OutboundCallPurpose = 'transactional' | 'requested_callback' | 'support';

export const CALL_TERMINAL_STATES: readonly CallCanonicalState[] =
  ['completed', 'failed', 'no_answer', 'busy', 'cancelled'];

export const CALL_STATE_COLORS: Record<CallCanonicalState, string> = {
  initiated:    'bg-gray-100 text-gray-700',
  ringing:      'bg-yellow-100 text-yellow-800',
  connected:    'bg-blue-100 text-blue-800',
  ai_active:    'bg-purple-100 text-purple-800',
  transferring: 'bg-orange-100 text-orange-800',
  human_active: 'bg-green-100 text-green-800',
  completed:    'bg-gray-100 text-gray-600',
  failed:       'bg-red-100 text-red-700',
  no_answer:    'bg-red-100 text-red-700',
  busy:         'bg-red-100 text-red-700',
  cancelled:    'bg-gray-100 text-gray-600',
};

export interface Call {
  id: string;
  conversation_id: string;
  company_id: string;
  brand_id: string | null;
  customer_id: string | null;
  lead_id: string | null;
  direction: CallDirection;
  from_number: string | null;
  to_number: string | null;
  provider: string;
  canonical_state: CallCanonicalState;
  canonical_state_label: string;
  started_at: string | null;
  answered_at: string | null;
  ended_at: string | null;
  duration_seconds: number | null;
  outcome: string | null;
  handled_by: CallHandledBy | null;
  transferred_at: string | null;
  transfer_target_type: string | null;
  verification_level: CallerVerificationLevel;
  has_transcript: boolean;
  has_recording: boolean;
  created_at: string | null;
}

/** A Brand's Voice number — the identity the outbound-call UI lets the user call FROM. */
export interface VoiceChannelProvider {
  id: string;
  company_id: string;
  brand_id: string | null;
  channel: string;
  display_name: string;
  phone_number: string | null;
  status: string;
}

export interface HumanTransferResult {
  result: 'bridged' | 'transfer_unavailable' | 'failed';
  task_id: string | null;
  failure_reason: string | null;
  call: Call;
}

/** One item of the cross-channel customer timeline — a Message OR a Call, never flattened into each other. */
export type EngagementTimelineItem =
  | {
      type: 'message';
      conversation_id: string;
      provider: CommunicationProvider;
      occurred_at: string;
      data: {
        id: string;
        direction: MessageDirection;
        sender_type: 'customer' | 'agent' | 'system';
        sender_name: string | null;
        message_type: MessageType;
        content: string | null;
      };
    }
  | {
      type: 'call';
      conversation_id: string;
      provider: CommunicationProvider;
      occurred_at: string;
      data: {
        id: string;
        direction: CallDirection;
        canonical_state: CallCanonicalState;
        duration_seconds: number | null;
        handled_by: CallHandledBy | null;
        outcome: string | null;
      };
    };

// ─── API Wrappers ─────────────────────────────────────────────────────────────

export interface PaginatedCepResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
