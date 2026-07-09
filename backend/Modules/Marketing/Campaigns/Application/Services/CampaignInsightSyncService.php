<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Application\Services;

use Modules\Marketing\Campaigns\Domain\Contracts\CampaignConnectorContract;
use Modules\Marketing\Campaigns\Domain\Models\Campaign;
use Modules\Marketing\Campaigns\Domain\Models\CampaignInsight;
use Modules\Marketing\Connections\Application\Services\ConnectorRegistry;
use Modules\Marketing\Connections\Domain\Models\MarketingConnection;
use Throwable;

/**
 * Fetches and persists campaign insights as IMMUTABLE historical snapshots.
 *
 * INVARIANT: Existing CampaignInsight records are NEVER updated.
 * Every sync call creates NEW rows, tagged with synced_at.
 *
 * Supports:
 *   - Standard date presets (last_7d, last_30d, last_90d)
 *   - Custom date ranges for historical backfill
 *   - Multi-level (campaign / adset / ad)
 */
final class CampaignInsightSyncService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    /**
     * Sync insights for a single campaign across all levels.
     *
     * @return array{campaign: int, adset: int, ad: int, errors: int}
     */
    public function syncForCampaign(
        Campaign            $campaign,
        MarketingConnection $connection,
        string              $datePreset = 'last_30d',
        ?string             $dateStart  = null,
        ?string             $dateStop   = null,
        bool                $includeAdSetLevel = true,
        bool                $includeAdLevel    = false,
    ): array {
        $connector = $this->resolveConnector($connection);
        if ($connector === null) {
            return ['campaign' => 0, 'adset' => 0, 'ad' => 0, 'errors' => 0];
        }

        $counts = ['campaign' => 0, 'adset' => 0, 'ad' => 0, 'errors' => 0];

        // Campaign-level insights
        try {
            $rows = $connector->fetchInsights(
                $campaign->external_campaign_id,
                'campaign',
                $connection,
                $datePreset,
                $dateStart,
                $dateStop,
            );

            foreach ($rows as $row) {
                $this->createInsightSnapshot($row, $campaign->id, null, null, $connection, 'campaign');
                $counts['campaign']++;
            }
        } catch (Throwable) {
            $counts['errors']++;
        }

        if (! $includeAdSetLevel) {
            return $counts;
        }

        // Ad Set-level insights
        foreach ($campaign->adSets as $adSet) {
            try {
                $rows = $connector->fetchInsights(
                    $adSet->external_ad_set_id,
                    'adset',
                    $connection,
                    $datePreset,
                    $dateStart,
                    $dateStop,
                );

                foreach ($rows as $row) {
                    $this->createInsightSnapshot($row, $campaign->id, $adSet->id, null, $connection, 'adset');
                    $counts['adset']++;
                }

                if (! $includeAdLevel) {
                    continue;
                }

                // Ad-level insights
                foreach ($adSet->ads as $ad) {
                    try {
                        $adRows = $connector->fetchInsights(
                            $ad->external_ad_id,
                            'ad',
                            $connection,
                            $datePreset,
                            $dateStart,
                            $dateStop,
                        );

                        foreach ($adRows as $row) {
                            $this->createInsightSnapshot($row, $campaign->id, $adSet->id, $ad->id, $connection, 'ad');
                            $counts['ad']++;
                        }
                    } catch (Throwable) {
                        $counts['errors']++;
                    }
                }
            } catch (Throwable) {
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Historical backfill: sync insights for a date range in chunks.
     *
     * @return array{campaign: int, errors: int}
     */
    public function backfill(
        Campaign            $campaign,
        MarketingConnection $connection,
        string              $dateStart,
        string              $dateStop,
    ): array {
        return $this->syncForCampaign(
            campaign:   $campaign,
            connection: $connection,
            dateStart:  $dateStart,
            dateStop:   $dateStop,
        );
    }

    /** @param array<string, mixed> $row */
    private function createInsightSnapshot(
        array               $row,
        string              $campaignId,
        ?string             $adSetId,
        ?string             $adId,
        MarketingConnection $connection,
        string              $level,
    ): CampaignInsight {
        $actions = $row['actions'] ?? [];

        // Extract named action values from the flat actions array
        $actionMap = [];
        foreach ((array) $actions as $action) {
            $actionMap[$action['action_type'] ?? ''] = (int) ($action['value'] ?? 0);
        }

        return CampaignInsight::create([
            'marketing_campaign_id'         => $campaignId,
            'marketing_campaign_ad_set_id'  => $adSetId,
            'marketing_campaign_ad_id'      => $adId,
            'marketing_connection_id'        => $connection->id,
            'connector_type'                => $connection->connector_type->value,
            'level'                         => $level,
            'date_start'                    => $row['date_start'] ?? now()->toDateString(),
            'date_stop'                     => $row['date_stop'] ?? now()->toDateString(),
            'spend'                         => isset($row['spend']) ? (float) $row['spend'] : null,
            'reach'                         => isset($row['reach']) ? (int) $row['reach'] : null,
            'impressions'                   => isset($row['impressions']) ? (int) $row['impressions'] : null,
            'frequency'                     => isset($row['frequency']) ? (float) $row['frequency'] : null,
            'cpm'                           => isset($row['cpm']) ? (float) $row['cpm'] : null,
            'cpc'                           => isset($row['cpc']) ? (float) $row['cpc'] : null,
            'ctr'                           => isset($row['ctr']) ? (float) $row['ctr'] / 100 : null,  // Meta returns %
            'clicks'                        => isset($row['clicks']) ? (int) $row['clicks'] : null,
            'outbound_clicks'               => isset($row['outbound_clicks'][0]['value']) ? (int) $row['outbound_clicks'][0]['value'] : null,
            'landing_page_views'            => $actionMap['landing_page_view'] ?? null,
            'video_views'                   => $actionMap['video_view'] ?? null,
            'messages'                      => $actionMap['onsite_conversion.messaging_conversation_started_7d'] ?? null,
            'leads'                         => $actionMap['lead'] ?? null,
            'purchases'                     => $actionMap['purchase'] ?? null,
            'add_to_cart'                   => $actionMap['add_to_cart'] ?? null,
            'initiate_checkout'             => $actionMap['initiate_checkout'] ?? null,
            'conversions'                   => $actionMap['offsite_conversion.fb_pixel_purchase'] ?? ($actionMap['purchase'] ?? null),
            'cost_per_result'               => isset($row['cost_per_result'][0]['value']) ? (float) $row['cost_per_result'][0]['value'] : null,
            'actions'                       => $actions,
            'synced_at'                     => now(),
            'created_at'                    => now(),
        ]);
    }

    private function resolveConnector(MarketingConnection $connection): ?CampaignConnectorContract
    {
        $type = $connection->connector_type->value;

        if (! $this->registry->has($type)) {
            return null;
        }

        $connector = $this->registry->get($type);

        return $connector instanceof CampaignConnectorContract ? $connector : null;
    }
}
