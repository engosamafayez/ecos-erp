<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Enums;

enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
