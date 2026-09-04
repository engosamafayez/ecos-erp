<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

enum ParticipantRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
