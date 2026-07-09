<?php

namespace Modules\CustomerEngagement\Domain\Enums;

enum ChannelProviderStatus: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
    case ERROR    = 'error';
}
