export type ConversationType = 'direct' | 'group';
export type MessageType = 'text' | 'image' | 'file' | 'voice' | 'system';
export type ParticipantRole = 'owner' | 'member';
export type TaskStatus = 'todo' | 'in_progress' | 'done' | 'cancelled';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';
export type OperationalContextType = 'order' | 'distribution_group' | 'trip' | 'driver';
export type AttachedToType = 'conversation' | 'message' | 'task';
export type TaskLabelColor = 'gray' | 'red' | 'orange' | 'yellow' | 'green' | 'blue' | 'purple';

export interface Conversation {
  id: string;
  company_id: string;
  type: ConversationType;
  title: string | null;
  created_by_user_id: number;
  team_id: string | null;
  last_message_at: string | null;
  unread_count: number | null;
  my_role: ParticipantRole | null;
  /** The caller's own mute preference for this conversation — a notification-only
   *  setting (architecture report §21); never affects unread_count or history. */
  my_muted: boolean;
  /** Active participants only, resolved with names — present whenever the backend
   *  eager-loads them (every current endpoint does). Absent only if a future
   *  endpoint returns a bare Conversation without that relation loaded. */
  participants?: ConversationParticipant[];
  created_at: string;
}

export interface MessageAttachment {
  name: string;
  mime_type: string | null;
  file_size: number | null;
  duration_seconds?: number | null;
}

export interface MentionedUser {
  id: number;
  name: string | null;
}

export interface Message {
  id: string;
  conversation_id: string;
  sender_user_id: number;
  sender_name?: string | null;
  type: MessageType;
  body: string | null;
  reply_to_message_id: string | null;
  mentioned_user_ids?: number[];
  /** Richer sibling of `mentioned_user_ids` carrying resolved names — additive, same data. */
  mentioned_users?: MentionedUser[];
  attachment: MessageAttachment | null;
  created_at: string;
}

export interface ConversationParticipant {
  id: string;
  conversation_id: string;
  user_id: number;
  name?: string | null;
  role: ParticipantRole;
  joined_at: string;
  left_at: string | null;
  last_read_at: string | null;
  last_read_message_id: string | null;
  muted_at: string | null;
}

/** Result of the "somebody to newly address" search — GET /collaboration/search/users. */
export interface AddressableUser {
  id: number;
  name: string;
  /** Low-sensitivity display field only (architecture report §15) — the search
   *  already matches against it; email/phone/role are still never returned. */
  job_title: string | null;
  is_driver: boolean;
}

export type ConversationMediaType = 'image' | 'file' | 'link';

/** One row of the conversation-info Media/Links/Documents tabs (architecture
 *  report §20) — `id` is the underlying message's own id, so the same secure
 *  attachment-fetch/download path messages already use applies unchanged. */
export interface ConversationMediaItem {
  id: string;
  type: MessageType;
  sender_name?: string | null;
  created_at: string;
  attachment: MessageAttachment | null;
  url: string | null;
  body: string | null;
}

export interface TaskActivityEntry {
  id: string;
  actor_user_id: number;
  actor_name?: string | null;
  event_type: string;
  from_value: string | null;
  to_value: string | null;
  created_at: string;
}

/** A Trello-style board LIST (organizational container) — never a second
 *  TaskStatus authority. Canonical lifecycle stays on Task.status. */
export interface TaskBoardList {
  id: string;
  name: string;
  position: number;
  archived_at: string | null;
}

export interface TaskLabel {
  id: string;
  name: string;
  color: TaskLabelColor;
}

export interface TaskChecklistItem {
  id: string;
  title: string;
  is_completed: boolean;
  position: number;
}

export interface TaskChecklist {
  id: string;
  title: string;
  position: number;
  items: TaskChecklistItem[];
}

/** Watcher, DISTINCT from the primary assignee. */
export interface TaskFollower {
  user_id: number;
  name?: string | null;
}

export interface Task {
  id: string;
  company_id: string;
  title: string;
  description: string | null;
  creator_user_id: number;
  creator_name?: string | null;
  assignee_user_id: number;
  assignee_name?: string | null;
  /** Additional assignees, ALONGSIDE (never replacing) the primary assignee above. */
  additional_assignees?: { id: number; name: string; job_title: string | null }[];
  team_id: string | null;
  priority: TaskPriority;
  status: TaskStatus;
  due_at: string | null;
  is_overdue: boolean;
  completed_at: string | null;
  cancelled_at: string | null;
  source_conversation_id: string | null;
  source_message_id: string | null;
  /** Null both when there is no source message AND when the viewer lacks
   *  independent access to it (backend-enforced — see TaskResource). The
   *  frontend must not try to tell those two cases apart from this field
   *  alone; `source_message_id` non-null + this null means "unavailable". */
  source_message_snapshot: string | null;
  activity?: TaskActivityEntry[];
  /** Board placement — organizational only, see TaskBoardList. Always present on
   *  a real API response; optional here only so existing fixtures/tests that
   *  predate the Trello board don't all need updating for an unrelated field. */
  task_list_id?: string | null;
  board_position?: number;
  list_name?: string | null;
  labels?: TaskLabel[];
  checklists?: TaskChecklist[];
  checklist_progress?: { completed: number; total: number } | null;
  followers?: TaskFollower[];
  followers_count?: number;
  comments_count?: number;
  attachments_count?: number;
  created_at: string;
  updated_at: string;
}

export interface TaskComment {
  id: string;
  task_id: string;
  author_user_id: number;
  author_name?: string | null;
  body: string;
  created_at: string;
}

export interface TaskAttachment {
  id: string;
  name: string;
  mime_type: string | null;
  file_size: number | null;
  uploaded_by: number | null;
  created_at: string;
}

export interface OperationalContextLink {
  id: string;
  context_type: OperationalContextType;
  context_id: string;
  attached_to_type: AttachedToType;
  attached_to_id: string;
}

export interface TaskFilters {
  scope?: 'mine' | 'created' | 'assigned';
  status?: TaskStatus;
  priority?: TaskPriority;
  overdue?: boolean;
  team_id?: string;
}
