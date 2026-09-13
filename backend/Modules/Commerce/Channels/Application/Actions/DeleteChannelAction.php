<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;

final class DeleteChannelAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        // TASK-...-WOO-05 (042A-R1 §6) — "Channel deletion/disconnect... DeleteChannelAction
        // must deregister before removing the row" so Woo stops delivering to a channel that
        // no longer exists to process them.
        private readonly WebhookManagerService $webhookManager,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $channel = $this->channels->findById($id);

        if ($channel === null) {
            throw new ChannelNotFoundException($id);
        }

        $this->webhookManager->deregisterAll($channel);
        $this->channels->delete($channel);

        return OperationResult::success(null, 'Channel deleted successfully.');
    }
}
