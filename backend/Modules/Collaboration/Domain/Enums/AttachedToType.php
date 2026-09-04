<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

/**
 * What an operational-context link is attached to. 'task' is not reachable
 * until Task 4 introduces InternalTask, but the link table already supports
 * it so Task 4 needs no schema change here either.
 */
enum AttachedToType: string
{
    case Conversation = 'conversation';
    case Message = 'message';
    case Task = 'task';
}
