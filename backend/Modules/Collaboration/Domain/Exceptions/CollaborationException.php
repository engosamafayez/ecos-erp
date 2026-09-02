<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Exceptions;

use RuntimeException;

/**
 * One exception type for Collaboration's own domain-state failures (not
 * authorization denials — those use Laravel's own AuthorizationException,
 * see architecture report §14, so that every "you may not do this" failure
 * in the platform maps the same way). Named constructors carry a reason
 * code, mirroring the established local pattern
 * (Manufacturing\ManufacturingPlanner\PlannerException,
 * ManufacturingExecution\ExecutionException).
 */
final class CollaborationException extends RuntimeException
{
    public const DRIVER_NOT_LINKED = 'DRIVER_NOT_LINKED';

    public const CROSS_CONVERSATION_REPLY = 'CROSS_CONVERSATION_REPLY';

    public const INVALID_MENTION = 'INVALID_MENTION';

    public const UNKNOWN_OPERATIONAL_CONTEXT_TYPE = 'UNKNOWN_OPERATIONAL_CONTEXT_TYPE';

    public const INVALID_STATUS_TRANSITION = 'INVALID_STATUS_TRANSITION';

    /** @param  array<string, mixed>  $context */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function driverNotLinked(int $driverId): self
    {
        return new self(
            "Driver {$driverId} has no linked user account and cannot be messaged.",
            self::DRIVER_NOT_LINKED,
            ['driver_id' => $driverId],
        );
    }

    public static function crossConversationReply(string $replyToMessageId, string $conversationId): self
    {
        return new self(
            'The message being replied to does not belong to this conversation.',
            self::CROSS_CONVERSATION_REPLY,
            ['reply_to_message_id' => $replyToMessageId, 'conversation_id' => $conversationId],
        );
    }

    public static function invalidMention(int $userId): self
    {
        return new self(
            "User {$userId} cannot be mentioned — not an active participant of this conversation.",
            self::INVALID_MENTION,
            ['user_id' => $userId],
        );
    }

    public static function unknownOperationalContextType(string $type): self
    {
        return new self(
            "'{$type}' is not a supported operational-context type.",
            self::UNKNOWN_OPERATIONAL_CONTEXT_TYPE,
            ['type' => $type],
        );
    }

    public static function invalidStatusTransition(string $from, string $to): self
    {
        return new self(
            "Cannot transition a task from '{$from}' to '{$to}'.",
            self::INVALID_STATUS_TRANSITION,
            ['from' => $from, 'to' => $to],
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
