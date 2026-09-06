<?php

declare(strict_types=1);

namespace Modules\Notifications\Application\Services;

use Modules\Admin\Configuration\Domain\Services\ConfigurationManager;
use Modules\Core\UserPreferences\Application\Services\UserPreferenceService;
use Modules\Notifications\Domain\Contracts\NotificationDeliveryPolicyInterface;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Notifications\Domain\Enums\SoundProfile;
use Modules\Notifications\Domain\ValueObjects\NotificationAttention;

final class NotificationDeliveryPolicy implements NotificationDeliveryPolicyInterface
{
    private const NOTIFICATIONS_SETTING_GROUP = 'notifications';

    private const COMPANY_ATTENTION_KEY = 'attention_defaults';

    private const USER_PREFERENCE_CATEGORY = 'notifications';

    /**
     * ADR-047 §26.4's locked default table, for channels other than in-app. NORMAL's
     * popup is "configurable" (default on, user may turn off); its sound is "optional"
     * (default off, user may opt in). HIGH/CRITICAL default both on.
     *
     * @var array<string, array{popup: bool, sound: bool}>
     */
    private const BASE_DEFAULTS = [
        'low' => ['popup' => false, 'sound' => false],
        'normal' => ['popup' => true, 'sound' => false],
        'high' => ['popup' => true, 'sound' => true],
        'critical' => ['popup' => true, 'sound' => true],
    ];

    public function __construct(
        private readonly ConfigurationManager $companySettings,
        private readonly UserPreferenceService $userPreferences,
    ) {}

    /** V1: unconditional. See the interface docblock for why this seam exists at all. */
    public function inAppIsMandatory(mixed $notifiable, ProvidesNotificationMetadataInterface $notification): bool
    {
        return true;
    }

    /**
     * MANDATORY SYSTEM POLICY > COMPANY DEFAULT > USER PREFERENCE (ADR-047 §14/§26.5):
     *
     * - Mandatory (CRITICAL priority, always — ADR-047 §26.4 "immediate"/§26.5 "a user
     *   can never disable... a Critical operational alert" — or a company policy that
     *   explicitly marks a lower priority mandatory): the company/base floor applies and
     *   user preference is never consulted.
     * - Otherwise: user preference wins when the user has set one; company default is
     *   the fallback baseline (itself falling back to the §26.4 base table when the
     *   company has configured nothing, which is the case for every company today —
     *   nothing seeds this yet, matching Task 2's own finding).
     */
    public function resolveAttention(mixed $notifiable, NotificationPriority $priority): NotificationAttention
    {
        $base = self::BASE_DEFAULTS[$priority->value];
        $company = $this->companyOverrideFor($notifiable, $priority);

        $mandatory = $priority === NotificationPriority::CRITICAL || ($company['mandatory'] ?? false) === true;

        $floorPopup = (bool) ($company['popup'] ?? $base['popup']);
        $floorSound = (bool) ($company['sound'] ?? $base['sound']);

        $userPref = $mandatory ? [] : $this->userPreferenceFor($notifiable);

        $popup = $mandatory ? $floorPopup : (bool) ($userPref['popup_enabled'] ?? $floorPopup);
        $sound = $mandatory ? $floorSound : (bool) ($userPref['sound_enabled'] ?? $floorSound);

        return new NotificationAttention(
            popup: $popup,
            sound: $sound,
            soundProfile: $sound ? $this->soundProfileFor($priority) : null,
        );
    }

    /** @return array{popup?: bool, sound?: bool, mandatory?: bool} */
    private function companyOverrideFor(mixed $notifiable, NotificationPriority $priority): array
    {
        $companyId = is_object($notifiable) ? ($notifiable->company_id ?? null) : null;

        if ($companyId === null) {
            return [];
        }

        $settings = $this->companySettings->getCompanySettings((string) $companyId, self::NOTIFICATIONS_SETTING_GROUP);

        return $settings[self::COMPANY_ATTENTION_KEY][$priority->value] ?? [];
    }

    /** @return array{popup_enabled?: bool, sound_enabled?: bool} */
    private function userPreferenceFor(mixed $notifiable): array
    {
        $userId = is_object($notifiable) ? ($notifiable->id ?? null) : null;

        if ($userId === null) {
            return [];
        }

        return $this->userPreferences->getByCategory((int) $userId, self::USER_PREFERENCE_CATEGORY)?->payload ?? [];
    }

    private function soundProfileFor(NotificationPriority $priority): SoundProfile
    {
        return match ($priority) {
            NotificationPriority::CRITICAL => SoundProfile::CRITICAL,
            NotificationPriority::HIGH => SoundProfile::IMPORTANT,
            NotificationPriority::NORMAL, NotificationPriority::LOW => SoundProfile::NORMAL,
        };
    }
}
