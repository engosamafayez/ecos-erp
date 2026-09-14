import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import type {
  Call,
  VoiceChannelProvider,
  HumanTransferResult,
  OutboundCallPurpose,
  EngagementTimelineItem,
  PaginatedCepResponse,
} from '../types/cep';

// TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 — thin React Query hooks over the
// Voice backend/API foundation built in Task 1 (routes/api.php `cep/voice/*`). Mirrors use-cep.ts's
// own conventions exactly (same BASE-relative axios calls, same invalidate-on-mutate pattern) —
// kept in a sibling file rather than merged into use-cep.ts because Voice is its own sub-bounded
// context, the same way its backend module is.

const BASE = '/api/cep/voice';

// ─── Channel providers (Brand calling identities) ──────────────────────────────

export interface VoiceChannelProvidersResult {
  data: VoiceChannelProvider[];
  /** TASK-...-017 §3/§5 — true when no Brand context was supplied at all: the server
   * deliberately returns an empty list rather than falling back to every company identity. */
  brand_context_required?: boolean;
}

/**
 * TASK-ECOS-V1.1-CRM-03-BRAND-VOICE-IDENTITY-FINAL-REMEDIATION-017 — brandId is REQUIRED to get
 * a real identity list back; omitting it (unresolved Brand context) still calls the endpoint so
 * the caller can distinguish "genuinely zero identities for this Brand" from "Brand context
 * required" via the response's own brand_context_required flag, never a client-side guess.
 */
export function useVoiceChannelProviders(companyId?: string, brandId?: string | null) {
  return useQuery({
    queryKey: ['voice-channel-providers', companyId, brandId],
    queryFn: async () => {
      const { data } = await axios.get<VoiceChannelProvidersResult>(
        `${BASE}/channel-providers`, { params: { company_id: companyId, brand_id: brandId ?? undefined } });
      return data;
    },
    enabled: !!companyId,
    staleTime: 60_000,
  });
}

// ─── Calls ──────────────────────────────────────────────────────────────────────

export function useVoiceCalls(params?: { company_id?: string; brand_id?: string; canonical_state?: string; per_page?: number }) {
  return useQuery({
    queryKey: ['voice-calls', params],
    queryFn: async () => {
      const { data } = await axios.get<PaginatedCepResponse<Call>>(`${BASE}/calls`, { params });
      return data;
    },
    staleTime: 10_000,
  });
}

export function useVoiceCall(id: string | null | undefined, options?: { refetchInterval?: number }) {
  return useQuery({
    queryKey: ['voice-call', id],
    queryFn: async () => {
      const { data } = await axios.get<{ data: Call }>(`${BASE}/calls/${id}`);
      return data.data;
    },
    enabled: !!id,
    refetchInterval: options?.refetchInterval,
  });
}

export function useInitiateOutboundCall(channelProviderId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { to_number: string; purpose: OutboundCallPurpose; customer_id?: string | null; brand_id?: string | null }) => {
      const { data } = await axios.post<{ data: Call }>(
        `${BASE}/channel-providers/${channelProviderId}/calls`, payload);
      return data.data;
    },
    onSuccess: (call) => {
      qc.invalidateQueries({ queryKey: ['voice-calls'] });
      qc.invalidateQueries({ queryKey: ['cep-conversation', call.conversation_id] });
      qc.invalidateQueries({ queryKey: ['cep-conversations'] });
    },
  });
}

// ─── Human transfer ─────────────────────────────────────────────────────────────

export function useRequestHumanTransfer(callId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (payload: { reason: string }) => {
      const { data } = await axios.post<{ data: HumanTransferResult }>(`${BASE}/calls/${callId}/transfer`, payload);
      return data.data;
    },
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['voice-call', callId] });
      qc.invalidateQueries({ queryKey: ['cep-conversation', result.call.conversation_id] });
    },
  });
}

// ─── Transcript / recording (permission-gated on the backend; fetched on demand only) ─────────

export function useCallTranscript(callId: string | null, enabled: boolean) {
  return useQuery({
    queryKey: ['voice-call-transcript', callId],
    queryFn: async () => {
      const { data } = await axios.get<{ data: { transcript_ref: string } | null }>(`${BASE}/calls/${callId}/transcript`);
      return data.data;
    },
    enabled: enabled && !!callId,
    staleTime: 60_000,
  });
}

export function useCallRecording(callId: string | null, enabled: boolean) {
  return useQuery({
    queryKey: ['voice-call-recording', callId],
    queryFn: async () => {
      const { data } = await axios.get<{ data: { recording_ref: string } | null }>(`${BASE}/calls/${callId}/recording`);
      return data.data;
    },
    enabled: enabled && !!callId,
    staleTime: 60_000,
  });
}

// ─── Cross-channel customer timeline (Task 1's EngagementTimelineService) ─────────────────────

export function useCustomerEngagementTimeline(customerId: string | null | undefined, enabled: boolean, limit = 50) {
  // EngagementTimelineController::forCustomer() reads company_id from the request explicitly
  // (unlike most CEP endpoints, it has no auth-inferred fallback) — the active company is the
  // one scope value every page in this app already knows via OrganizationContext.
  const { activeCompanyId } = useOrganizationContext();

  return useQuery({
    queryKey: ['cep-customer-timeline', activeCompanyId, customerId, limit],
    queryFn: async () => {
      const { data } = await axios.get<{ data: EngagementTimelineItem[] }>(
        `/api/cep/customers/${customerId}/timeline`, { params: { company_id: activeCompanyId, limit } });
      return data.data;
    },
    enabled: enabled && !!customerId && !!activeCompanyId,
    staleTime: 15_000,
  });
}
