<?php

declare(strict_types=1);

namespace Modules\Organization\Brands\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Configuration\Domain\Models\BrandShippingRule;
use Modules\Admin\Configuration\Domain\Models\DeliveryGeography;
use Modules\Admin\Configuration\Domain\Models\DeliveryZone;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Brands\Domain\Models\BrandDeliveryTimeSlot;
use Modules\Organization\Brands\Domain\Models\BrandGovernorateSettings;

final class BrandDeliveryController extends Controller
{
    use HasApiResponse;

    /**
     * Returns the full geography tree for a brand (active governorates + active zones + shipping costs).
     * Used by the manual order form to populate the governorate and zone dropdowns in a single request.
     */
    public function geography(string $brandId): JsonResponse
    {
        $geographies = DeliveryGeography::where('brand_id', $brandId)
            ->where('is_active', true)
            ->with([
                'zones' => fn ($q) => $q
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Load zone-level shipping rules (override prices) keyed by zone id
        $zoneRules = BrandShippingRule::where('brand_id', $brandId)
            ->where('is_enabled', true)
            ->whereNotNull('delivery_zone_id')
            ->get()
            ->keyBy('delivery_zone_id');

        $governorates = $geographies->map(function (DeliveryGeography $geo) use ($zoneRules) {
            return [
                'id' => $geo->id,
                'name' => $geo->name,
                'default_shipping_cost' => $geo->default_shipping_cost,
                'zones' => $geo->zones->map(function (DeliveryZone $zone) use ($zoneRules, $geo) {
                    $override = $zoneRules->get($zone->id);
                    // PART 5: effective = zone override ?? governorate default
                    $effective = $override?->shipping_cost ?? $geo->default_shipping_cost;

                    return [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'shipping_cost_override' => $override?->shipping_cost,
                        'shipping_cost' => $effective, // legacy field — keeps order form working
                    ];
                })->values(),
            ];
        })->values();

        return $this->success(['governorates' => $governorates]);
    }

    /**
     * Returns a configuration health report for a brand.
     * Used by the manual order form to gate order creation on complete setup.
     *
     * CD-01 (TASK-ECOS-COMMERCE-PRE-USER-REVIEW-REMEDIATION-002 §2). This gate is
     * reconciled against the SAME authorities the New Order form actually consumes.
     * It previously asserted three legacy tables the form no longer reads, which
     * blocked order creation for brands that were, in fact, fully configured on the
     * live path:
     *
     *   - `config_delivery_geographies` — superseded as the form's governorate source
     *     by the brand shipping engine (`brand_governorate_settings`, served by
     *     GET /brands/{brand}/shipping/governorates, which `useBrandShippingGovernorates`
     *     builds the governorate combo from). Kept below only as the FALLBACK arm,
     *     because the form itself still falls back to `geography()` when the shipping
     *     engine returns no rows — so readiness must accept either authority, exactly
     *     as the form does. No duplicate authority is introduced by this: the two arms
     *     mirror one existing frontend fallback, they do not define a new source.
     *
     *   - `config_delivery_zones` — REMOVED as a readiness condition. The form has no
     *     blocking dependency on it: `delivery_zone_id` is optional on both
     *     StoreManualOrderRequest and UpdateOrderRequest, and the shipping engine's
     *     city tier (`brand_city_settings`, the new-engine analogue of a zone) is
     *     likewise optional — ShippingValidationService only rejects a city that
     *     EXISTS and is disabled, and even then defers to the brand's configured
     *     `unsupported_city_action`. Requiring zone coverage would therefore assert a
     *     condition order creation does not need.
     *
     *   - `config_brand_shipping_rules` — REMOVED as a readiness condition. It is not
     *     the pricing authority: ShippingQuoteService::calculatePrice() resolves
     *     brand_city_settings.shipping_price → brand_governorate_settings.shipping_price
     *     → logistics_governorates.default_shipping_price → 0.0, so a price ALWAYS
     *     resolves and the operator can additionally override it
     *     (`shipping_cost_source = 'override'`). Asserting this table gated New Order
     *     on a table no shipping decision reads.
     *
     * Backend authority over valid order creation is untouched: the real admission
     * rule remains CreateManualOrderAction's own
     * `validateAndResolveShipping()`/ShippingValidationResult::isRejected() check plus
     * every FormRequest rule. This endpoint stays a pre-flight configuration report,
     * and it is not the thing that authorises an order.
     */
    public function health(string $brandId): JsonResponse
    {
        Brand::findOrFail($brandId);

        // An active sales channel is genuinely required: the manual order form resolves
        // the BRAND from the selected channel (CreateManualOrderAction::resolveBrandId),
        // so with no active channel there is no brand policy, no geography and no order.
        $channelsOk = Channel::where('brand_id', $brandId)->where('is_active', true)->exists();

        // At least one SELECTABLE governorate must exist, or the operator cannot enter a
        // delivery destination at all. Canonical arm first, legacy fallback second —
        // mirroring the form's own resolution order.
        $geoOk = BrandGovernorateSettings::where('brand_id', $brandId)->where('is_enabled', true)->exists()
            || DeliveryGeography::where('brand_id', $brandId)->where('is_active', true)->exists();

        // At least one active delivery window — unchanged authority, still the table the
        // form's window selector reads and the table `delivery_window_id` validates against.
        $windowsOk = BrandDeliveryTimeSlot::where('brand_id', $brandId)->where('is_active', true)->exists();

        return $this->success([
            'is_ready' => $channelsOk && $geoOk && $windowsOk,
            'checks' => [
                'channels' => $channelsOk,
                'delivery_geography' => $geoOk,
                'delivery_windows' => $windowsOk,
            ],
        ]);
    }

    /**
     * Returns the enabled delivery windows for a brand.
     * Used by the manual order form delivery window selector.
     */
    public function windows(string $brandId): JsonResponse
    {
        $windows = BrandDeliveryTimeSlot::where('brand_id', $brandId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('start_time')
            ->get()
            ->map(fn (BrandDeliveryTimeSlot $w) => [
                'id' => $w->id,
                'label' => $w->name,
                'starts_at' => $w->start_time,
                'ends_at' => $w->end_time,
            ])
            ->values();

        return $this->success($windows);
    }
}
