import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

/**
 * CORE-02 Task 1 — public, unauthenticated invitation acceptance. Mirrors authService's shape;
 * calls the guest `/auth/invitations/*` routes (see routes/api.php's `auth` prefix group).
 */
export type InvitationPreview = {
  email: string;
  expires_at: string | null;
};

export type AcceptInvitationPayload = {
  token: string;
  password: string;
  password_confirmation: string;
};

export const invitationAcceptService = {
  async show(token: string): Promise<InvitationPreview> {
    const { data } = await api.get<ApiResponse<InvitationPreview>>('/auth/invitations/show', { params: { token } });
    return data.data;
  },

  async accept(payload: AcceptInvitationPayload): Promise<void> {
    await api.post('/auth/invitations/accept', payload);
  },
};
