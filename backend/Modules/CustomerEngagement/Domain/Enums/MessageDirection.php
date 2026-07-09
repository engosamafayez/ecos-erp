<?php

namespace Modules\CustomerEngagement\Domain\Enums;

enum MessageDirection: string
{
    case Inbound  = 'inbound';
    case Outbound = 'outbound';
}
