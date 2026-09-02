<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\DTO;

use Modules\Collaboration\Domain\Enums\TaskPriority;

final readonly class CreateTaskData
{
    public function __construct(
        public int $creatorUserId,
        public string $title,
        public ?string $description,
        public ?int $assigneeUserId,
        public TaskPriority $priority,
        public ?string $dueAt,
        public ?string $teamId = null,
        public ?string $sourceMessageId = null,
    ) {}
}
