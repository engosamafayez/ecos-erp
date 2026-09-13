<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Domain\Enums;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 §23 — the WooCommerce Connector plugin's own
 * connection health, derived from Channel::connectorHealth(). Distinct from
 * {@see ChannelLifecycleState} (a business decision) and {@see ConnectionStatus} (the result
 * of the last ECOS→Woo REST test) — this is specifically "is the plugin itself alive and
 * still talking to us".
 */
enum ConnectorHealth: string
{
    case NeverConnected = 'never_connected';
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::NeverConnected => 'Never Connected',
            self::Healthy => 'Connected',
            self::Degraded => 'Degraded',
            self::Disconnected => 'Disconnected',
        };
    }
}
