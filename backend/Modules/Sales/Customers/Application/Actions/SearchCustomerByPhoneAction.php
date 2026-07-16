<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Sales\Customers\Domain\Models\Customer;

/**
 * Find an active customer by phone or mobile number.
 * Also loads order statistics for the customer intelligence panel.
 */
final class SearchCustomerByPhoneAction extends BaseAction
{
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var string $phone */
        $phone = $arguments[0];

        $customer = Customer::where(function ($query) use ($phone): void {
            $query->where('phone', $phone)->orWhere('mobile', $phone);
        })
            ->where('is_active', true)
            ->with('addresses')
            ->first();

        if ($customer === null) {
            return OperationResult::success(null, 'No customer found with this phone number.');
        }

        $stats = $this->buildStats($customer->id);

        return OperationResult::success([
            'customer' => [
                'id'          => $customer->id,
                'name'        => $customer->name,
                'phone'       => $customer->phone,
                'mobile'      => $customer->mobile,
                'governorate' => $customer->governorate,
                'city'        => $customer->city,
                'area'        => $customer->area,
                'notes'       => $customer->notes,
            ],
            'addresses' => $customer->addresses->map(fn($a) => [
                'id'            => $a->id,
                'is_default'    => $a->is_default,
                'governorate'   => $a->governorate,
                'city'          => $a->city,
                'area'          => $a->area,
                'address_line'  => $a->address_line,
                'building'      => $a->building,
                'floor'         => $a->floor,
                'apartment'     => $a->apartment,
                'landmark'      => $a->landmark,
                'address_notes' => $a->address_notes,
                'google_maps_lat' => $a->google_maps_lat,
                'google_maps_lng' => $a->google_maps_lng,
                'google_maps_url' => $a->google_maps_url,
            ])->values(),
            'stats' => $stats,
        ], 'Customer found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(string $customerId): array
    {
        $orders = \Modules\Commerce\Orders\Domain\Models\Order::where('customer_id', $customerId)
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as cnt, SUM(total) as revenue')
            ->groupBy('status')
            ->get();

        $totalOrders  = (int)   $orders->sum('cnt');
        $totalRevenue = (float) $orders->sum('revenue');

        // "Delivered" = both delivered + completed (order reached the customer)
        $deliveredCount  = (int) ($orders->whereIn('status', ['delivered', 'completed'])->sum('cnt'));
        $completedCount  = (int) $orders->where('status', 'completed')->sum('cnt');
        $cancelledCount  = (int) $orders->where('status', 'cancelled')->sum('cnt');
        $returnedCount   = (int) $orders->where('status', 'returned')->sum('cnt');

        $successRate = $totalOrders > 0
            ? round(($deliveredCount / $totalOrders) * 100, 1)
            : 0.0;

        $avgOrderValue = $totalOrders > 0
            ? round($totalRevenue / $totalOrders, 2)
            : 0.0;

        $firstOrderDate = \Modules\Commerce\Orders\Domain\Models\Order::where('customer_id', $customerId)
            ->whereNull('deleted_at')
            ->orderBy('order_date')
            ->value('order_date');

        $lastOrderDate = \Modules\Commerce\Orders\Domain\Models\Order::where('customer_id', $customerId)
            ->whereNull('deleted_at')
            ->orderByDesc('order_date')
            ->value('order_date');

        return [
            'total_orders'     => $totalOrders,
            'delivered'        => $deliveredCount,
            'completed'        => $completedCount,
            'cancelled'        => $cancelledCount,
            'returned'         => $returnedCount,
            'success_rate'     => $successRate,
            'lifetime_value'   => $totalRevenue,
            'avg_order_value'  => $avgOrderValue,
            'first_order_date' => $firstOrderDate,
            'last_order_date'  => $lastOrderDate,
        ];
    }
}
