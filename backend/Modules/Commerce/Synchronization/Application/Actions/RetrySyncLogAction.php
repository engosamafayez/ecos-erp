<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Synchronization\Application\Jobs\InventorySyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\PriceSyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProductSyncJob;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Models\SyncLog;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Purchasing\GoodsReceipts\Domain\Models\StockBalance;

final class RetrySyncLogAction extends BaseAction
{
    /**
     * Arguments:
     *   [0] SyncLog $log
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var SyncLog $log */
        $log = $arguments[0];

        if ($log->direction !== SyncDirection::Outbound) {
            return OperationResult::failure('Only outbound sync logs can be retried.');
        }

        $channel = $log->channel;

        if ($channel === null) {
            return OperationResult::failure('Channel no longer exists.');
        }

        if ($log->entity_id === null) {
            return OperationResult::failure('Cannot retry: no entity ID recorded.');
        }

        $product = Product::find($log->entity_id);

        if ($product === null) {
            return OperationResult::failure('Product no longer exists.');
        }

        match ($log->entity_type) {
            SyncEntityType::Product => ProductSyncJob::dispatch($channel, $product),
            SyncEntityType::Price => PriceSyncJob::dispatch($channel, $product),
            SyncEntityType::Inventory => InventorySyncJob::dispatch(
                $channel,
                $product,
                (float) StockBalance::query()->where('product_id', $product->id)->sum('quantity'),
            ),
            default => null,
        };

        if (! in_array($log->entity_type, [SyncEntityType::Product, SyncEntityType::Price, SyncEntityType::Inventory], true)) {
            return OperationResult::failure("Entity type [{$log->entity_type->value}] does not support retry.");
        }

        return OperationResult::success(null, 'Retry job dispatched successfully.');
    }
}
