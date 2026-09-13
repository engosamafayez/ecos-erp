import axios from 'axios';

import { api } from '@/lib/axios';
import type { AssistantApiResponse, AssistantMessageRequestPayload } from '@/features/ai-assistant/types/assistant';

/**
 * The ONE canonical client for POST /api/ai/assistant/message (§12 — no raw
 * fetch calls scattered through components). Never talks to a model provider
 * directly; ECOS's own backend is the only thing this ever calls.
 */
export const assistantService = {
  async sendMessage(payload: AssistantMessageRequestPayload): Promise<AssistantApiResponse> {
    const { data } = await api.post<{ data: AssistantApiResponse }>('/ai/assistant/message', payload);

    return data.data;
  },
};

export function isValidationError(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 422;
}

export function isRateLimitedError(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 429;
}

export function isAuthError(error: unknown): boolean {
  return axios.isAxiosError(error) && (error.response?.status === 401 || error.response?.status === 403);
}
