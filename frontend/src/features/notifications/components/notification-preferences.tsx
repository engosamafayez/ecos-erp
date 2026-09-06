import { useTranslation } from 'react-i18next';
import { Check, Lock, Settings, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { toast } from '@/components/ds/use-toast';

import {
  useAttentionPolicy,
  useNotificationPreferences,
  useUpdateNotificationPreferences,
} from '../hooks/use-notifications';
import { NOTIFICATION_PRIORITIES } from '../types/notification';

/**
 * ADR-047 §26.1/§26.5 — "My Profile → Notification Preferences", scoped here to the
 * bell itself rather than a standalone Profile page (none exists yet; this task does
 * not add one — TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 §19
 * "do not redesign the entire app shell").
 *
 * Exactly two editable controls exist because exactly two are backed by real storage
 * today (`user_preferences`, category `notifications`, `{popup_enabled, sound_enabled}`
 * — Task 3's own reuse of the existing generic preference table). Everything else on
 * screen is the *effective*, already-resolved-server-side result
 * (MANDATORY POLICY > COMPANY DEFAULT > USER PREFERENCE, ADR-047 §14/§26.5) — shown
 * truthfully, including which priorities are locked, never re-derived client-side.
 */
export function NotificationPreferencesButton() {
  const { t } = useTranslation('common');

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t(($) => $.notifications.settings)}>
          <Settings className="size-4" aria-hidden />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-80">
        <NotificationPreferencesPanel />
      </PopoverContent>
    </Popover>
  );
}

function NotificationPreferencesPanel() {
  const { t } = useTranslation('common');
  const preferences = useNotificationPreferences();
  const policy = useAttentionPolicy();
  const update = useUpdateNotificationPreferences();

  const popupEnabled = preferences.data?.popup_enabled ?? true;
  const soundEnabled = preferences.data?.sound_enabled ?? true;

  function save(next: { popup_enabled: boolean; sound_enabled: boolean }) {
    update.mutate(next, {
      onError: () => toast.error(t(($) => $.notifications.preferences.saveFailed)),
    });
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <p className="text-sm font-semibold">{t(($) => $.notifications.preferences.title)}</p>
        <p className="text-muted-foreground mt-0.5 text-xs">
          {t(($) => $.notifications.preferences.description)}
        </p>
      </div>

      <div className="flex items-center justify-between gap-2">
        <span className="text-sm">{t(($) => $.notifications.preferences.popupLabel)}</span>
        <Switch
          checked={popupEnabled}
          disabled={preferences.isLoading}
          onCheckedChange={(checked) => save({ popup_enabled: checked, sound_enabled: soundEnabled })}
        />
      </div>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm">{t(($) => $.notifications.preferences.soundLabel)}</span>
        <Switch
          checked={soundEnabled}
          disabled={preferences.isLoading}
          onCheckedChange={(checked) => save({ popup_enabled: popupEnabled, sound_enabled: checked })}
        />
      </div>

      <div className="border-t pt-3">
        <p className="text-muted-foreground text-xs font-medium">
          {t(($) => $.notifications.preferences.effectiveTitle)}
        </p>
        <table className="mt-2 w-full text-xs">
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
