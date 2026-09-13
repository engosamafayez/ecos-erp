<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Application\DTO\ChannelDTO;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Services\SalesChannelCodeGeneratorService;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;

final class CreateChannelAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly SalesChannelCodeGeneratorService $codeGenerator,
        // TASK-...-WOO-05 (042A-R1 §6/§8) — if credentials are supplied at creation time
        // (rather than added later via UpdateChannelAction, which already registers on
        // rotation), attempt registration immediately rather than leaving the channel
        // credentialed but unregistered until the next unrelated update.
        private readonly WebhookManagerService $webhookManager,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var ChannelDTO $dto */
        $dto = $arguments[0];

        $code = $dto->code ?? $this->codeGenerator->next($dto->brand_id);

        $attributes = array_merge($dto->channelAttributes(), ['code' => $code]);

        $channel = $this->channels->create(
            $attributes,
            $dto->credentialAttributes(),
        );

        if ($dto->credentialAttributes() !== null) {
            $this->webhookManager->registerAll($channel);
            $channel = $channel->fresh();
        }

        return OperationResult::success($channel, 'Channel created successfully.');
    }
}
