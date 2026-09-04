<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

/**
 * Image/File/Voice/System exist now so the schema and casts never need to
 * change when Task 3 adds media — only Text is producible by any Task 2
 * application action (enforced in SendMessageRequest, not here).
 */
enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case File = 'file';
    case Voice = 'voice';
    case System = 'system';
}
