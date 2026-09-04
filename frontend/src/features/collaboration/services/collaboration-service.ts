import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type { AddressableUser, Conversation, ConversationParticipant, Message } from '../types';

/**
 * Every canonical operation this calls is the exact backend authority built
 * in Tasks 2-3 — no client-side reimplementation of participation checks,
 * read/unread derivation, or media authorization. A 403/404 here is the
 * backend's own answer, not something this layer interprets or overrides.
 */

export async function listConversations(): Promise<Conversation[]> {
  const { data } = await api.get<ApiResponse<Conversation[]>>('/collaboration/conversations');
  return data.data;
}

export async function getConversation(id: string): Promise<Conversation> {
  const { data } = await api.get<ApiResponse<Conversation>>(`/collaboration/conversations/${id}`);
  return data.data;
}

export async function startDirectConversation(targetUserId: number): Promise<Conversation> {
  const { data } = await api.post<ApiResponse<Conversation>>('/collaboration/conversations/direct', {
    target_user_id: targetUserId,
  });
  return data.data;
}

export async function createGroupConversation(params: {
  title: string;
  participantUserIds: number[];
  teamId?: string | null;
}): Promise<Conversation> {
  const { data } = await api.post<ApiResponse<Conversation>>('/collaboration/conversations/groups', {
    title: params.title,
    participant_user_ids: params.participantUserIds,
    team_id: params.teamId ?? null,
  });
  return data.data;
}

export async function addParticipant(conversationId: string, userId: number): Promise<ConversationParticipant> {
  const { data } = await api.post<ApiResponse<ConversationParticipant>>(
    `/collaboration/conversations/${conversationId}/participants`,
    { user_id: userId },
  );
  return data.data;
}

export async function removeParticipant(conversationId: string, userId: number): Promise<void> {
  await api.delete(`/collaboration/conversations/${conversationId}/participants/${userId}`);
}

export async function listMessages(
  conversationId: string,
  params: { beforeMessageId?: string; afterMessageId?: string; limit?: number } = {},
): Promise<Message[]> {
  const { data } = await api.get<ApiResponse<Message[]>>(`/collaboration/conversations/${conversationId}/messages`, {
    params: {
      before_message_id: params.beforeMessageId,
      after_message_id: params.afterMessageId,
      limit: params.limit ?? 50,
    },
  });
  return data.data;
}

export interface SendMessagePayload {
  type?: 'text' | 'image' | 'file' | 'voice';
  body?: string;
  replyToMessageId?: string | null;
  mentionedUserIds?: number[];
  file?: File | Blob | null;
  voiceDurationSeconds?: number | null;
}

/** MediaRecorder's `.mimeType` varies by browser (webm/Chrome, mp4/Safari) — name the
 *  blob accordingly so the filename at least matches its real content; the backend's
 *  `mimes:` rule sniffs actual bytes server-side regardless, so this is cosmetic only. */
function voiceBlobFilename(blob: Blob): string {
  const ext = /mp4|aac/i.test(blob.type) ? 'm4a' : /ogg/i.test(blob.type) ? 'ogg' : 'webm';
  return `voice-message.${ext}`;
}

export async function sendMessage(conversationId: string, payload: SendMessagePayload): Promise<Message> {
  const form = new FormData();
  if (payload.type) form.append('type', payload.type);
  if (payload.body) form.append('body', payload.body);
  if (payload.replyToMessageId) form.append('reply_to_message_id', payload.replyToMessageId);
  (payload.mentionedUserIds ?? []).forEach((id) => form.append('mentioned_user_ids[]', String(id)));
  if (payload.file) form.append('file', payload.file, payload.file instanceof File ? payload.file.name : voiceBlobFilename(payload.file));
  if (payload.voiceDurationSeconds != null) form.append('voice_duration_seconds', String(payload.voiceDurationSeconds));

  const { data } = await api.post<ApiResponse<Message>>(
    `/collaboration/conversations/${conversationId}/messages`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  );
  return data.data;
}

export async function markConversationRead(conversationId: string, lastReadMessageId?: string): Promise<void> {
  await api.patch(`/collaboration/conversations/${conversationId}/read`, {
    last_read_message_id: lastReadMessageId ?? null,
  });
}

/** Fetches attachment bytes through the authorized streaming endpoint — never a public URL. */
export async function fetchMessageAttachmentBlob(messageId: string): Promise<Blob> {
  const { data } = await api.get(`/collaboration/messages/${messageId}/attachment`, { responseType: 'blob' });
  return data;
}

export async function searchMessages(query: string, limit = 20): Promise<Message[]> {
  const { data } = await api.get<ApiResponse<Message[]>>('/collaboration/search/messages', {
    params: { q: query, limit },
  });
  return data.data;
}

/**
 * "Somebody to newly address" — new direct conversation, new group member, task
 * assignee/reassignment. NOT a general company directory: the backend excludes the
 * caller themselves and filters out any driver-linked user the caller isn't both
 * permitted and in-scope to message (SearchAddressableUsersAction). A driver who
 * doesn't yet appear here is a capability gate, not a bug in this search.
 */
export async function searchAddressableUsers(query: string, limit = 20): Promise<AddressableUser[]> {
  const { data } = await api.get<ApiResponse<AddressableUser[]>>('/collaboration/search/users', {
    params: { q: query, limit },
  });
  return data.data;
}
