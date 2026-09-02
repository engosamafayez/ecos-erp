<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\DTO;

use Illuminate\Http\UploadedFile;
use Modules\Collaboration\Domain\Enums\MessageType;

final readonly class SendMessageData
{
    /** @param  list<int>  $mentionedUserIds */
    public function __construct(
        public string $conversationId,
        public int $senderUserId,
        public MessageType $type,
        public ?string $body,
        public ?string $replyToMessageId = null,
        public array $mentionedUserIds = [],
        public ?UploadedFile $file = null,
        public ?int $voiceDurationSeconds = null,
    ) {}
}
