<?php

declare(strict_types=1);

namespace Modules\Commerce\OrderImport\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Carbon;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\OrderImport\Application\DTO\OrderImportOptionsDTO;
use Modules\Commerce\OrderImport\Application\Services\WooCommerceOrderImporter;

final class ImportOrdersAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly WooCommerceOrderImporter $importer,
    ) {}

    /**
     * @param  array{after?: string|null, historical?: bool, batch_id?: string|null}  $options
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        /** @var array<string, mixed> $options */
        $options = $arguments[1] ?? [];

        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        $after = isset($options['after']) && $options['after'] !== null && $options['after'] !== ''
            ? Carbon::parse((string) $options['after'])
            : null;

        $result = $this->importer->import($channel, new OrderImportOptionsDTO(
            after: $after,
            historical: (bool) ($options['historical'] ?? false),
            batchId: isset($options['batch_id']) ? (string) $options['batch_id'] : null,
        ));

        return OperationResult::success($result->toArray(), $result->summary());
    }
}
