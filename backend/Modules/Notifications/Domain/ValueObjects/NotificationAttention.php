<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\ValueObjects;

use Modules\Notifications\Domain\Enums\SoundProfile;

/**
 * ADR-047 §26.6/§26.7: the resolved decision for one priority — whether a popup and/or
 * sound should accompany a notification's arrival. Never affects in-app persistence
 * (ADR-047 §9/§14): a notification is always written and always in the feed regardless
 * of this value; this only governs the best-effort attention layer on top of it.
 */
final readonly class NotificationAttention
{
    public function __construct(
        public bool $popup,
        public bool $sound,
        public ?SoundProfile $soundProfile,
        /**
         * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 (ADR-047
         * §10/§26.5): true when this priority's popup/sound is fixed by MANDATORY SYSTEM
         * POLICY (either the CRITICAL floor or a company policy explicitly marking a
         * lower priority mandatory) and cannot be changed by user preference. The
         * preferences UI must show this truthfully rather than presenting a control the
         * backend will silently ignore.
         */
        public bool $locked,
    ) {}

    /** @return array{popup: bool, sound: bool, sound_profile: ?string, locked: bool} */
    public function toArray(): array
    {
        return [
            'popup' => $this->popup,
            'sound' => $this->sound,
            'sound_profile' => $this->soundProfile?->value,
            'locked' => $this->locked,
        ];
    }
}
