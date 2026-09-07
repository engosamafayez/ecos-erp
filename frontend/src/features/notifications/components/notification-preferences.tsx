import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowUpRight, Check, Lock, Settings, Volume2, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { toast } from '@/components/ds/use-toast';

import {
  useAttentionPolicy,
  useNotificationPreferences,
  useNotificationTypeCatalog,
  useUpdateNotificationPreferences,
} from '../hooks/use-notifications';
import { playAttentionSound } from '../lib/notification-sound';
import { NOTIFICATION_PRIORITIES, type NotificationTypeCatalogEntry } from '../types/notification';

/** TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §8 — only modules that actually exist in the catalog. */
const MODULE_ORDER = ['pricing', 'preparation', 'driver', 'collaboration'] as const;

/**
 * ADR-047 §26.1/§26.5 — "My Profile → Notification Preferences". Originally scoped to
 * the bell itself only (no standalone page — TASK-ECOS-NOTIFICATIONS-CENTER-
 * PREFERENCES-AND-LIVE-DELIVERY-004 §19 "do not redesign the entire app shell"); a real
 * user could not find that popover-only surface (DEV review after Task 008), so
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009 added a routed page
 * (frontend/src/features/notifications/pages/my-preferences-page.tsx, ROUTES.myPreferences)
 * that renders this exact same panel — never a second implementation.
 *
 * Exactly two editable controls exist because exactly two are backed by real storage
 * today (`user_preferences`, category `notifications`, `{popup_enabled, sound_enabled}`
 * — Task 3's own reuse of the existing generic preference table). Everything else on
 * screen is the *effective*, already-resolved-server-side result
 * (MANDATORY POLICY > COMPANY DEFAULT > USER PREFERENCE, ADR-047 §14/§26.5) — shown
 * truthfully, including which priorities are locked, never re-derived client-side.
 */
export function NotificationPreferencesButton({
  onOpenFullSettings,
}: {
  /**
   * D1 — the compact panel's "Notification Settings" button calls this. Owned by the
   * caller (the bell's NotificationCenter, which already has a real Router context)
   * rather than imported here, so this component and its panel never need
   * react-router-dom themselves — matching this file's existing, deliberately
   * router-free test setup.
   */
  onOpenFullSettings?: () => void;
} = {}) {
  const { t } = useTranslation('common');

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t(($) => $.notifications.settings)}>
          <Settings className="size-4" aria-hidden />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-80">
        {/* D1 — the bell's popover is QUICK CONTROLS ONLY: popup/sound/volume, a
            concise summary, and a link to the full page. The per-priority table and
            the full per-type toggle list now live only on that full page. */}
        <NotificationPreferencesPanel compact onOpenFullSettings={onOpenFullSettings} />
      </PopoverContent>
    </Popover>
  );
}

/**
 * Exported (TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009) so both the
 * bell's popover and a routed page can render the same editable controls — one
 * preference authority, never two implementations.
 *
 * `hideHeader` lets a full page supply its own page-level title without rendering this
 * same title text twice.
 *
 * D1 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005) — `compact`
 * is the bell popover's QUICK CONTROLS ONLY mode: popup/sound/volume + Test Sound stay
 * (every surface needs them), but the per-priority effective table and the full
 * per-type toggle list are dropped in favor of one concise summary line and a button to
 * the full Notification Settings page. Default (`compact=false`, unchanged) keeps
 * rendering everything — used by that full page, and by the legacy /me/preferences
 * page, which this task deliberately leaves alone (still works, per D1's own
 * instruction not to break it).
 */
export function NotificationPreferencesPanel({
  hideHeader = false,
  compact = false,
  onOpenFullSettings,
}: {
  hideHeader?: boolean;
  compact?: boolean;
  onOpenFullSettings?: () => void;
} = {}) {
  const { t } = useTranslation('common');
  const preferences = useNotificationPreferences();
  const policy = useAttentionPolicy();
  const update = useUpdateNotificationPreferences();
  const catalog = useNotificationTypeCatalog();

  const popupEnabled = preferences.data?.popup_enabled ?? true;
  const soundEnabled = preferences.data?.sound_enabled ?? true;
  const soundVolume = preferences.data?.sound_volume ?? 1;

  // Local draft while dragging — committing on every `onChange` tick would fire a PUT
  // per pixel of drag; this fires exactly one, on release/keyup. Resyncing the draft
  // when the server value changes is done during render (React's own recommended
  // "adjusting state when a prop changes" pattern), not in an effect — an effect here
  // would commit the stale draft to the screen for one frame before correcting it.
  const [volumeDraft, setVolumeDraft] = useState(soundVolume);
  const [lastSyncedVolume, setLastSyncedVolume] = useState(soundVolume);
  if (soundVolume !== lastSyncedVolume) {
    setLastSyncedVolume(soundVolume);
    setVolumeDraft(soundVolume);
  }

  /** Always sends the complete payload — PUT /me/preferences/{category} is a full replace. */
  function save(patch: Partial<NonNullable<typeof preferences.data>>) {
    update.mutate(
      {
        popup_enabled: popupEnabled,
        sound_enabled: soundEnabled,
        sound_volume: soundVolume,
        type_overrides: preferences.data?.type_overrides,
        ...patch,
      },
      { onError: () => toast.error(t(($) => $.notifications.preferences.saveFailed)) },
    );
  }

  /**
   * D4 — a pure local preview: calls the SAME playback function/asset the real
   * attention layer uses (never a second sound path), at the currently-SELECTED volume
   * (the live drag draft, so moving the slider and testing feels connected even before
   * release commits it). Never touches the Notification DB, unread count, or a popup —
   * this only reaches the Web Audio API. Deliberately NOT gated on `soundEnabled`: a
   * manual "let me hear it" action is reasonably useful even with ambient sound
   * currently off (the judgement call D4 asks to record). `volume` here is always > 0
   * while the button is enabled (see `disabled` below), so a `false` return means a
   * genuine autoplay/permission/unsupported-API failure — the one bounded, helpful
   * message this shows, never a silent no-op or a thrown error. At `volume=0` the
   * function itself correctly reports success (a deliberately silent setting is not a
   * failure) — so no error shows just because the slider happens to be at 0.
   */
  function testSound() {
    const played = playAttentionSound('normal', volumeDraft);
    if (!played) {
      toast.error(t(($) => $.notifications.preferences.testSoundFailed));
    }
  }

  return (
    <div className="flex flex-col gap-4">
      {!hideHeader && (
        <div>
          <p className="text-sm font-semibold">{t(($) => $.notifications.preferences.title)}</p>
          <p className="text-muted-foreground mt-0.5 text-xs">
            {t(($) => $.notifications.preferences.description)}
          </p>
        </div>
      )}

      <div className="flex items-center justify-between gap-2">
        <span className="text-sm">{t(($) => $.notifications.preferences.popupLabel)}</span>
        <Switch
          checked={popupEnabled}
          disabled={preferences.isLoading}
          onCheckedChange={(checked) => save({ popup_enabled: checked })}
        />
      </div>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm">{t(($) => $.notifications.preferences.soundLabel)}</span>
        <Switch
          checked={soundEnabled}
          disabled={preferences.isLoading}
          onCheckedChange={(checked) => save({ sound_enabled: checked })}
        />
      </div>

      {/* TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §6 — volume, not the
          system/device volume: this only scales playAttentionSound()'s own synthesized
          gain. Disabled (not hidden) while sound itself is off, so the control's own
          state is never lost/reset by toggling sound back on. */}
      <div className="flex items-center gap-2">
        <Volume2 className="text-muted-foreground size-4 shrink-0" aria-hidden />
        <span className="text-sm">{t(($) => $.notifications.preferences.volumeLabel)}</span>
        <input
          type="range"
          min={0}
          max={1}
          step={0.05}
          value={volumeDraft}
          disabled={preferences.isLoading || !soundEnabled}
          aria-label={t(($) => $.notifications.preferences.volumeLabel)}
          onChange={(e) => setVolumeDraft(Number(e.target.value))}
          onMouseUp={(e) => save({ sound_volume: Number((e.target as HTMLInputElement).value) })}
          onTouchEnd={(e) => save({ sound_volume: Number((e.target as HTMLInputElement).value) })}
          onKeyUp={(e) => save({ sound_volume: Number((e.target as HTMLInputElement).value) })}
          className="ms-auto h-1.5 w-24 shrink-0 accent-primary disabled:opacity-40"
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={testSound}
          disabled={preferences.isLoading}
          className="h-7 shrink-0 px-2 text-xs"
        >
          {t(($) => $.notifications.preferences.testSound)}
        </Button>
      </div>

      {compact ? (
        <div className="border-t pt-3">
          {/* D1 — concise current-state summary, not the full per-priority table:
              the bell popover is quick controls only. */}
          <p className="text-muted-foreground text-xs">
            {catalog.data && catalog.data.length > 0
              ? t(($) => $.notifications.preferences.summaryTypesEnabled, {
                  enabled: catalog.data.filter((entry) => entry.enabled).length,
                  total: catalog.data.length,
                })
              : t(($) => $.notifications.preferences.description)}
          </p>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onOpenFullSettings?.()}
            className="mt-1.5 h-7 gap-1 px-0 text-xs font-medium text-primary hover:bg-transparent hover:text-primary/80"
          >
            {t(($) => $.notifications.preferences.openSettingsPage)}
            <ArrowUpRight className="size-3.5 rtl:-scale-x-100" aria-hidden />
          </Button>
        </div>
      ) : (
        <>
      <div className="border-t pt-3">
        <p className="text-muted-foreground text-xs font-medium">
          {t(($) => $.notifications.preferences.effectiveTitle)}
        </p>
        <p className="text-muted-foreground/80 mt-0.5 text-[11px]">
          {t(($) => $.notifications.preferences.effectiveReadOnlyHint)}
        </p>
        <table className="mt-2 w-full text-xs" role="presentation" aria-readonly="true">
          <thead>
            <tr className="text-muted-foreground">
              <th className="text-start font-medium">{t(($) => $.notifications.preferences.columnPriority)}</th>
              <th className="text-center font-medium">{t(($) => $.notifications.preferences.columnPopup)}</th>
              <th className="text-center font-medium">{t(($) => $.notifications.preferences.columnSound)}</th>
              <th className="w-5" />
            </tr>
          </thead>
          <tbody>
            {NOTIFICATION_PRIORITIES.map((priority) => {
              const effective = policy.data?.[priority];
              return (
                <tr key={priority} className="border-t">
                  <td className="py-1.5">{t(($) => $.notifications.priority[priority])}</td>
                  <td className="text-center">
                    <BoolIcon value={effective?.popup} />
                  </td>
                  <td className="text-center">
                    <BoolIcon value={effective?.sound} />
                  </td>
                  <td className="text-center">
                    {effective?.locked ? (
                      <Tooltip>
                        <TooltipTrigger asChild>
                          <Lock
                            className="text-muted-foreground mx-auto size-3"
                            aria-label={t(($) => $.notifications.preferences.locked)}
                          />
                        </TooltipTrigger>
                        <TooltipContent>{t(($) => $.notifications.preferences.lockedHint)}</TooltipContent>
                      </Tooltip>
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <NotificationTypeToggles onToggle={(key, enabled) => save({
        type_overrides: { ...preferences.data?.type_overrides, [key]: enabled },
      })} />
        </>
      )}
    </div>
  );
}

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §8/§9/§10 — the canonical
 * catalog, grouped by business module, one ON/OFF per real condition. Never a
 * frontend-hardcoded list: entirely driven by GET /api/notifications/type-catalog.
 * Arabic name/description are primary; the technical key is secondary/debug-only.
 */
function NotificationTypeToggles({ onToggle }: { onToggle: (key: string, enabled: boolean) => void }) {
  const { t } = useTranslation('common');
  const catalog = useNotificationTypeCatalog();

  if (!catalog.data || catalog.data.length === 0) return null;

  const byModule = new Map<string, NotificationTypeCatalogEntry[]>();
  catalog.data.forEach((entry) => {
    const list = byModule.get(entry.module) ?? [];
    list.push(entry);
    byModule.set(entry.module, list);
  });

  const orderedModules = [
    ...MODULE_ORDER.filter((m) => byModule.has(m)),
    ...[...byModule.keys()].filter((m) => !(MODULE_ORDER as readonly string[]).includes(m)),
  ];

  return (
    <div className="border-t pt-3">
      <p className="text-muted-foreground text-xs font-medium">
        {t(($) => $.notifications.preferences.typesTitle)}
      </p>
      <div className="mt-2 flex flex-col gap-3">
        {orderedModules.map((moduleKey) => (
          <div key={moduleKey}>
            <p className="text-muted-foreground/80 text-[11px] font-semibold uppercase tracking-wide">
              {t(($) => $.notifications.moduleGroups[moduleKey as keyof typeof $.notifications.moduleGroups])}
            </p>
            <div className="mt-1 flex flex-col gap-2">
              {byModule.get(moduleKey)!.map((entry) => (
                <div key={entry.key} className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm">{entry.name_ar}</p>
                    <p className="text-muted-foreground mt-0.5 text-[11px]">{entry.description_ar}</p>
                    <p className="text-muted-foreground/60 mt-0.5 text-[10px]" dir="ltr">{entry.key}</p>
                  </div>
                  <Switch
                    checked={entry.enabled}
                    disabled={!entry.user_can_disable}
                    onCheckedChange={(checked) => onToggle(entry.key, checked)}
                    aria-label={entry.name_ar}
                    className="mt-0.5 shrink-0"
                  />
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

function BoolIcon({ value }: { value: boolean | undefined }) {
  if (value === undefined) return <span className="text-muted-foreground">—</span>;
  return value ? (
    <Check className="text-emerald-600 mx-auto size-3.5" aria-hidden />
  ) : (
    <X className="text-muted-foreground/50 mx-auto size-3.5" aria-hidden />
  );
}
