import { isAxiosError } from 'axios';

import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type { AttentionPolicyMap, NotificationPage, NotificationPreferences } from '../types/notification';

const PREFERENCES_CATEGORY = 'notifications';

/**
 * The caller's own notification feed.
 *
 * Scoped server-side to the authenticated notifiable — there is no user or
 * company parameter to pass, and none should be added: ownership is the gate.
 */
export const notificationsService = {
  async list(params: { page?: number; perPage?: number; unread?: boolean } = {}) {
    const { data } = await api.get<ApiResponse<NotificationPage>>('/notifications', {
      params: {
        page: params.page,
        per_page: params.perPage,
        unread: params.unread ? 1 : undefined,
      },
    });
    return data.data;
  },

  async markRead(id: string): Promise<void> {
    await api.patch(`/notifications/${id}/read`);
  },

  async markAllRead(): Promise<number> {
    const { data } = await api.post<ApiResponse<{ updated: number }>>(
      '/notifications/mark-all-read',
    );
    return data.data.updated;
  },

  /** The permitted set, not everything — server-side still gated by ownership. */
  async markReadSet(ids: string[]): Promise<number> {
    const { data } = await api.post<ApiResponse<{ updated: number }>>('/notifications/mark-read', {
      ids,
    });
    return data.data.updated;
  },

  /**
   * The caller's resolved popup/sound policy by priority (ADR-047 §14/§26.4-§26.8) —
   * a small, rarely-changing map, not the feed itself.
   */
  async attentionPolicy(): Promise<AttentionPolicyMap> {
    const { data } = await api.get<ApiResponse<AttentionPolicyMap>>('/notifications/attention-policy');
    return data.data;
  },

  /**
   * The user's own notification preferences — ADR-047 §26.1 "My Profile → Notification
   * Preferences", reusing the existing generic `/me/preferences/{category}` mechanism
   * (Core/UserPreferences) rather than a Notifications-specific preference engine. `null`
   * means the user has never set one; the resolved defaults come from
   * `attentionPolicy()`, never guessed here.
   */
  async getPreferences(): Promise<NotificationPreferences | null> {
    try {
      const { data } = await api.get<ApiResponse<{ payload: NotificationPreferences }>>(
        `/me/preferences/${PREFERENCES_CATEGORY}`,
      );
      return data.data.payload;
    } catch (error) {
      if (isAxiosError(error) && error.response?.status === 404) return null;
      throw error;
    }
  },

  /** PUT is a full replace (Core/UserPreferences semantics) — always send the complete payload. */
  async updatePreferences(payload: NotificationPreferences): Promise<void> {
    await api.put(`/me/preferences/${PREFERENCES_CATEGORY}`, { payload });
  },
};
