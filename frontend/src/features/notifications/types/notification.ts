/**
 * The authenticated user's notification feed — GET /api/notifications.
 *
 * The wire shape is Laravel's `notifications` table, passed through: `type` is
 * the producing notification's FQCN and `data` is whatever that class's
 * `toDatabase()` returned. Producers own their payloads, so `data` is read
 * defensively here rather than typed as a fixed contract that would break the
 * first time a producer adds a field.
 */
export type RawNotification = {
  id: string;
  /** The producing notification class's FQCN. The only stable discriminator. */
  type: string;
  data: Record<string, unknown>;
  read_at: string | null;
  created_at: string | null;
  /**
   * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002 (ADR-047 §24) read-model fields. Optional:
   * a row written before the schema extension, or by a producer that has not migrated
   * onto the shared contract, simply omits them — nothing here is backfilled/guessed.
   * Not yet rendered by the Notification Center (that UI work is a later task); this is
   * the foundation those fields need to exist on the wire first.
   */
  company_id?: string | null;
  priority?: NotificationPriority | null;
  category?: NotificationCategory | null;
  source_module?: string | null;
  deep_link?: { entity_type: string; entity_id: string; action_key: string | null; route: string | null } | null;
  dedupe_key?: string | null;
  group_key?: string | null;
  expires_at?: string | null;
  dismissed_at?: string | null;
};

/** ADR-047 §6 — the locked 8-value taxonomy. Mirrors the backend's NotificationCategory enum. */
export const NOTIFICATION_CATEGORIES = [
  'alert',
  'task',
  'approval',
  'assignment',
  'warning',
  'mention',
  'ai_notification',
  'exception',
] as const;

export type NotificationCategory = (typeof NOTIFICATION_CATEGORIES)[number];

/** ADR-047 §7 — always orthogonal to category. Mirrors the backend's NotificationPriority enum. */
export const NOTIFICATION_PRIORITIES = ['low', 'normal', 'high', 'critical'] as const;

export type NotificationPriority = (typeof NOTIFICATION_PRIORITIES)[number];

/** ADR-047 §26.8 — mirrors the backend's SoundProfile enum. At most three, ever. */
export const SOUND_PROFILES = ['normal', 'important', 'critical'] as const;

export type SoundProfile = (typeof SOUND_PROFILES)[number];

/**
 * TASK-ECOS-NOTIFICATIONS-ATTENTION-EXPERIENCE-003 — the resolved popup/sound decision
 * for one priority, already applying MANDATORY SYSTEM POLICY > COMPANY DEFAULT > USER
 * PREFERENCE server-side (ADR-047 §14/§26.5). The client never re-derives this chain —
 * it only applies the answer to a freshly-observed notification of that priority.
 */
export type AttentionSettings = {
  popup: boolean;
  sound: boolean;
  sound_profile: SoundProfile | null;
  /**
   * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 (ADR-047 §10/§26.5)
   * — true when MANDATORY SYSTEM POLICY fixes this priority's popup/sound; the
   * preferences UI must show it as non-editable rather than a control the backend
   * silently ignores.
   */
  locked: boolean;
};

export type AttentionPolicyMap = Record<NotificationPriority, AttentionSettings>;

/**
 * The user-editable half of the precedence chain (ADR-047 §26.5) — global on/off, not
 * per-priority: the per-priority *effective* result (including what mandatory policy
 * fixes regardless of these) comes from {@link AttentionPolicyMap}, not from here.
 */
export type NotificationPreferences = {
  popup_enabled?: boolean;
  sound_enabled?: boolean;
};

export type NotificationPage = {
  data: RawNotification[];
  unread_count: number;
  meta: { page: number; perPage: number; total: number; lastPage: number };
};

/**
 * The module a notification came from, derived from its FQCN.
 *
 * This is a grouping of what producers exist today, not a taxonomy anyone has
 * to maintain: an unrecognised namespace falls to `other` and still shows,
 * rather than being dropped because no category claimed it.
 */
export const NOTIFICATION_SOURCES = [
  'operations',
  'logistics',
  'marketing',
  'pos',
  'system',
  'other',
] as const;

export type NotificationSource = (typeof NOTIFICATION_SOURCES)[number];

/** Producer-declared severity. Anything else is treated as `info`. */
export const NOTIFICATION_SEVERITIES = ['info', 'success', 'warning', 'error'] as const;

export type NotificationSeverity = (typeof NOTIFICATION_SEVERITIES)[number];

/** What the notification centre actually renders. */
export type UiNotification = {
  id: string;
  source: NotificationSource;
  severity: NotificationSeverity;
  /** The producer's own message. Empty when the producer supplied none. */
  message: string;
  createdAt: string | null;
  read: boolean;
  /**
   * Read-model foundation only (ADR-047 §24) — not yet rendered (badges/grouping are a
   * later task). Absent (not defaulted) when the source row predates the schema
   * extension, so a consumer can tell "no priority was ever assigned" apart from "low".
   */
  priority?: NotificationPriority;
  category?: NotificationCategory;
  /**
   * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 (ADR-047 §8) — a
   * typed reference, never a raw URL. Absent when the producer supplied none (true for
   * every current producer). Whether it renders as an action is decided separately by
   * `resolveNotificationTarget()` — an unrecognised `entityType` here is not an error.
   */
  deepLink?: { entityType: string; entityId: string; actionKey: string | null; route: string | null };
};

const SOURCE_BY_SEGMENT: Record<string, NotificationSource> = {
  Operations: 'operations',
  Logistics: 'logistics',
  Marketing: 'marketing',
  POS: 'pos',
  System: 'system',
};

function sourceOf(type: string): NotificationSource {
  // FQCNs look like `Modules\Operations\Preparation\...\WaveCompletedNotification`.
  const segment = type.split('\\')[1] ?? '';
  return SOURCE_BY_SEGMENT[segment] ?? 'other';
}

function severityOf(data: Record<string, unknown>): NotificationSeverity {
  const raw = data.severity;
  return NOTIFICATION_SEVERITIES.includes(raw as NotificationSeverity)
    ? (raw as NotificationSeverity)
    : 'info';
}

/**
 * Normalises one wire notification for display.
 *
 * `message` is the producer's own text and is shown verbatim. It is not
 * translated, because it is data the backend composed — inventing a key for it
 * here would mean guessing at strings the producer may change tomorrow.
 */
export function toUiNotification(raw: RawNotification): UiNotification {
  const message = typeof raw.data.message === 'string' ? raw.data.message : '';

  return {
    id: raw.id,
    source: sourceOf(raw.type),
    severity: severityOf(raw.data),
    message,
    createdAt: raw.created_at,
    read: raw.read_at !== null,
    ...(raw.priority ? { priority: raw.priority } : {}),
    ...(raw.category ? { category: raw.category } : {}),
    ...(raw.deep_link
      ? {
          deepLink: {
            entityType: raw.deep_link.entity_type,
            entityId: raw.deep_link.entity_id,
            actionKey: raw.deep_link.action_key,
            route: raw.deep_link.route,
          },
        }
      : {}),
  };
}
