<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — backs the "shipping mapping acknowledged" go-live
 * gate (042A-R1 §5), which has no other existing signal to read. A dedicated, audited Action
 * rather than a bare field PATCH — consistent with how this module already treats "an operator
 * confirmed X" as an event (SetInitialOrdersImportPolicyAction), not a freely-editable column.
 */
final class AcknowledgeShippingMappingAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        $channel->update(['shipping_mapping_reviewed_at' => now()]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'channel.shipping_mapping_reviewed', []);

        return OperationResult::success($channel, 'Shipping mapping acknowledged.');
    }
}
