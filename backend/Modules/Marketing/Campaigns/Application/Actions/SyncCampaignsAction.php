<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Application\Actions;

use Modules\Marketing\Campaigns\Application\Services\CampaignInsightSyncService;
use Modules\Marketing\Campaigns\Application\Services\CampaignSyncService;
use Modules\Marketing\Campaigns\Domain\Models\Campaign;
use Modules\Marketing\Connections\Domain\Models\MarketingConnection;
use Modules\Marketing\Connections\Domain\Models\MarketingAuditLog;
use Throwable;

/**
 * Orchestrates a full campaign sync for a connection.
 *
 * 1. Discovers campaigns / ad sets / ads / creatives
 * 2. Syncs campaign-level insights for the last 30 days
 * 3. Logs the sync to the marketing audit log
 */
final class SyncCampaignsAction
{
    public function __construct(
        private readonly CampaignSyncService        $syncService,
        private readonly CampaignInsightSyncService $insightService,
    ) {}

    /**
     * @return array{
     *   campaigns: int, ad_sets: int, ads: int, creatives: int,
     *   insights: int, errors: int
     * }
     */
    public function execute(
        MarketingConnection $connection,
        bool               $syncInsights     = true,
        bool               $syncCreatives    = true,
        string             $insightDatePreset = 'last_30d',
        ?string            $actorId          = null,
    ): array {
        $counts = $this->syncService->syncForConnection($connection, $syncCreatives);

        $insightCount = 0;

        if ($syncInsights) {
            $campaigns = Campaign::where('marketing_connection_id', $connection->id)
                ->with(['adSets.ads'])
                ->get();

            foreach ($campaigns as $campaign) {
                try {
                    $insightResult = $this->insightService->syncForCampaign(
                        campaign:           $campaign,
                        connection:         $connection,
                        datePreset:         $insightDatePreset,
                        includeAdSetLevel:  true,
                        includeAdLevel:     false,
                    );
                    $insightCount += $insightResult['campaign'] + $insightResult['adset'];
                } catch (Throwable) {
                    $counts['errors']++;
                }
            }
        }

        MarketingAuditLog::record(
            entityType:    'connection',
            entityId:      $connection->id,
            action:        'campaigns_synced',
            actorId:       $actorId,
            after:         [...$counts, 'insights' => $insightCount],
            connectionId:  $connection->id,
            connectorType: $connection->connector_type->value,
        );

        return [...$counts, 'insights' => $insightCount];
    }
}
