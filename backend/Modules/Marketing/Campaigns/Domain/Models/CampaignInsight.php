<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Marketing\Campaigns\Domain\Enums\CampaignLevel;

/**
 * Campaign Insight — Immutable Historical Metric Snapshot.
 *
 * CRITICAL: This model is APPEND-ONLY.
 * Every sync creates NEW rows. Existing rows are NEVER modified.
 * Query "latest" state by ordering synced_at DESC.
 *
 * @property string            $id
 * @property string            $marketing_campaign_id
 * @property string|null       $marketing_campaign_ad_set_id
 * @property string|null       $marketing_campaign_ad_id
 * @property string            $marketing_connection_id
 * @property string            $connector_type
 * @property CampaignLevel     $level
 * @property \Carbon\Carbon    $date_start
 * @property \Carbon\Carbon    $date_stop
 * @property string|null       $date_preset
 * @property float|null        $spend
 * @property int|null          $reach
 * @property int|null          $impressions
 * @property float|null        $frequency
 * @property float|null        $cpm
 * @property float|null        $cpc
 * @property float|null        $ctr
 * @property int|null          $clicks
 * @property int|null          $outbound_clicks
 * @property int|null          $landing_page_views
 * @property int|null          $video_views
 * @property int|null          $messages
 * @property int|null          $leads
 * @property int|null          $purchases
 * @property int|null          $add_to_cart
 * @property int|null          $initiate_checkout
 * @property int|null          $conversions
 * @property float|null        $cost_per_result
 * @property array|null        $actions
 * @property \Carbon\Carbon    $synced_at
 */
class CampaignInsight extends Model
{
    use HasUuids;

    protected $table      = 'marketing_campaign_insights';
    protected $guarded    = [];
    public    $timestamps = false;  // manually managed (only created_at + synced_at)

    protected function casts(): array
    {
        return [
            'level'       => CampaignLevel::class,
            'date_start'  => 'date',
            'date_stop'   => 'date',
            'spend'       => 'decimal:4',
            'frequency'   => 'decimal:6',
            'cpm'         => 'decimal:4',
            'cpc'         => 'decimal:4',
            'ctr'         => 'decimal:6',
            'cost_per_result' => 'decimal:4',
            'actions'     => 'array',
            'synced_at'   => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'marketing_campaign_id');
    }

    /** @return BelongsTo<CampaignAdSet, $this> */
    public function adSet(): BelongsTo
    {
        return $this->belongsTo(CampaignAdSet::class, 'marketing_campaign_ad_set_id');
    }

    /** @return BelongsTo<CampaignAd, $this> */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(CampaignAd::class, 'marketing_campaign_ad_id');
    }
}
