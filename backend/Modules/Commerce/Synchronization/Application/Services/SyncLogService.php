<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Commerce\Synchronization\Domain\Models\SyncLog;

final class SyncLogService
{
    /**
     * @param array<string, mixed>|null $requestPayload
     */
    public function createLog(
        ?Channel $channel,
        SyncEntityType $entityType,
        SyncDirection $direction,
        string $action,
        ?string $entityId = null,
        SyncStatus $status = SyncStatus::Processing,
        ?array $requestPayload = null,
    ): SyncLog {
        return SyncLog::create([
            'channel_id' => $channel?->id,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'direction' => $direction->value,
            'action' => $action,
            'status' => $status->value,
            'request_payload' => $requestPayload,
            'synced_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $responsePayload
     */
    public function markSuccess(SyncLog $log, ?array $responsePayload = null): void
    {
        $log->update([
            'status' => SyncStatus::Success->value,
            'response_payload' => $responsePayload,
            'error_message' => null,
            'synced_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $responsePayload
     */
    public function markFailed(SyncLog $log, string $errorMessage, ?array $responsePayload = null): void
    {
        $log->update([
            'status' => SyncStatus::Failed->value,
            'error_message' => $errorMessage,
            'response_payload' => $responsePayload,
            'synced_at' => now(),
        ]);
    }
}
