<?php

declare(strict_types=1);

namespace Modules\Marketing\Synchronization\Application\Actions;

use Modules\Marketing\Assets\Domain\Enums\AssetHealth;
use Modules\Marketing\Assets\Domain\Models\MarketingAsset;
use Modules\Marketing\Connections\Application\Services\ConnectorRegistry;
use Modules\Marketing\Connections\Domain\Models\MarketingConnection;
use Modules\Marketing\MappingEngine\Application\Services\MappingSuggestionService;
use Modules\Marketing\Synchronization\Domain\Enums\SyncStatus;
use Modules\Marketing\Synchronization\Domain\Enums\SyncType;
use Modules\Marketing\Synchronization\Domain\Events\SynchronizationCompleted;
use Modules\Marketing\Synchronization\Domain\Events\SynchronizationFailed;
use Modules\Marketing\Synchronization\Domain\Events\SynchronizationStarted;
use Modules\Marketing\Synchronization\Domain\Models\MarketingSyncLog;
use Throwable;

/**
 * Runs a full or incremental sync for a connection.
 *
 * Discovers all assets from the connector, then upserts them in the
 * marketing_assets table. Auto-applies matching profiles after discovery.
 */
final class RunSyncAction
{
    public function __construct(
        private readonly ConnectorRegistry       $registry,
        private readonly MappingSuggestionService $suggestionService,
    ) {}

    public function execute(
        MarketingConnection $connection,
        SyncType            $syncType = SyncType::Full,
        ?string             $triggeredBy = null,
    ): MarketingSyncLog {
        // Create a sync log entry
        $syncLog = MarketingSyncLog::create([
            'marketing_connection_id' => $connection->id,
            'sync_type'               => $syncType->value,
            'status'                  => SyncStatus::Pending->value,
            'started_at'              => now(),
            'triggered_by'            => $triggeredBy,
        ]);

        if (! $this->registry->has($connection->connector_type->value)) {
            $syncLog->update([
                'status'        => SyncStatus::Failed->value,
                'completed_at'  => now(),
                'error_message' => "No connector registered for type [{$connection->connector_type->value}].",
            ]);

            return $syncLog;
        }

        $connector = $this->registry->get($connection->connector_type->value);

        try {
            $syncLog->update(['status' => SyncStatus::Running->value]);

            event(new SynchronizationStarted(
                syncLogId:     $syncLog->id,
                connectionId:  $connection->id,
                connectorType: $connection->connector_type,
                syncType:      $syncType->value,
            ));

            $discovered = $connector->discoverAssets($connection);

            $created = 0;
            $updated = 0;
            $failed  = 0;

            foreach ($discovered as $rawAsset) {
                try {
                    [$asset, $isNew] = $this->upsertAsset($connection, $rawAsset);

                    if ($isNew) {
                        $created++;
                        // Auto-apply mapping profiles for newly discovered assets
                        $this->suggestionService->suggestForAsset($asset, $connection->company_id);
                    } else {
                        $updated++;
                    }
                } catch (Throwable) {
                    $failed++;
                }
            }

            $syncLog->update([
                'status'            => SyncStatus::Completed->value,
                'completed_at'      => now(),
                'assets_discovered' => count($discovered),
                'assets_created'    => $created,
                'assets_updated'    => $updated,
                'assets_failed'     => $failed,
            ]);

            $connection->update(['last_synced_at' => now()]);

            event(new SynchronizationCompleted(
                syncLogId:       $syncLog->id,
                connectionId:    $connection->id,
                connectorType:   $connection->connector_type,
                assetsDiscovered: count($discovered),
                assetsCreated:   $created,
                assetsUpdated:   $updated,
                assetsFailed:    $failed,
            ));

        } catch (Throwable $e) {
            $syncLog->update([
                'status'        => SyncStatus::Failed->value,
                'completed_at'  => now(),
                'error_message' => $e->getMessage(),
            ]);

            event(new SynchronizationFailed(
                syncLogId:     $syncLog->id,
                connectionId:  $connection->id,
                connectorType: $connection->connector_type,
                errorMessage:  $e->getMessage(),
            ));
        }

        return $syncLog->fresh() ?? $syncLog;
    }

    /**
     * Upsert a raw asset descriptor from the connector into marketing_assets.
     *
     * @param  array<string, mixed> $rawAsset
     * @return array{0: MarketingAsset, 1: bool}  [asset, isNew]
     */
    private function upsertAsset(MarketingConnection $connection, array $rawAsset): array
    {
        $existing = MarketingAsset::where('connector_type', $connection->connector_type->value)
            ->where('external_id', $rawAsset['external_id'])
            ->first();

        $isNew = $existing === null;

        $asset = MarketingAsset::updateOrCreate(
            [
                'connector_type' => $connection->connector_type->value,
                'external_id'    => $rawAsset['external_id'],
            ],
            [
                'company_id'              => $connection->company_id,
                'marketing_connection_id' => $connection->id,
                'asset_type'             => $rawAsset['asset_type'],
                'name'                   => $rawAsset['name'],
                'status'                 => $rawAsset['status'] ?? 'active',
                'health_status'          => AssetHealth::Healthy->value,
                'health_checked_at'      => now(),
                'asset_metadata'         => $rawAsset['metadata'] ?? null,
                'last_synced_at'         => now(),
                'next_sync_at'           => now()->addHours(6),
            ],
        );

        return [$asset, $isNew];
    }
}
