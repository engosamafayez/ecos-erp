<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Domain\Enums;

enum ResetOperationStatus: string
{
    case Executing = 'executing';
    case Completed = 'completed';
    case Failed = 'failed';
}
