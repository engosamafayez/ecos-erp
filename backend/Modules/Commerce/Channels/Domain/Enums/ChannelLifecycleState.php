<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Domain\Enums;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — architecture authority 042A-R1 §5.
 *
 * `DRAFT` / `CONFIGURED` / `READY` are informational, computed states describing how far a
 * channel's configuration has progressed (see ChannelGoLiveReadinessService) — nothing writes
 * them directly. `LIVE` is the one state that is ONLY ever written by an explicit, audited
 * transition (TransitionChannelToLiveAction) once every go-live gate passes; saving credentials,
 * flipping is_active, or any other configuration change never moves a channel into it by itself.
 * `PAUSED` and `DISABLED` are explicit, audited operator actions taken FROM `LIVE`.
 */
enum ChannelLifecycleState: string
{
    case Draft = 'draft';
    case Configured = 'configured';
    case Ready = 'ready';
    case Live = 'live';
    case Paused = 'paused';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Configured => 'Configured',
            self::Ready => 'Ready',
            self::Live => 'Live',
            self::Paused => 'Paused',
            self::Disabled => 'Disabled',
        };
    }
}
