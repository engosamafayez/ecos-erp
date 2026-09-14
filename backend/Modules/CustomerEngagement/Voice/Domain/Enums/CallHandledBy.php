<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Enums;

enum CallHandledBy: string
{
    case Ai = 'ai';
    case Human = 'human';
    case Both = 'both';
}
