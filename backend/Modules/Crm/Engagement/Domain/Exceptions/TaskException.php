<?php

declare(strict_types=1);

namespace Modules\Crm\Engagement\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A Follow-Up/task-domain refusal, rendered as a 422 with an actionable message. */
class TaskException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }

    public static function notOpen(string $title): self
    {
        return new self("\"{$title}\" is no longer open — only an open task can be changed this way.");
    }
}
