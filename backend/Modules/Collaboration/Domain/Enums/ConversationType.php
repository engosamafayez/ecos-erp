<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
}
