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
            'customer' => $customer,
            'addresses' => $customer->addresses,
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

        $totalOrders = (int) $orders->sum('cnt');
        $totalRevenue = (float) $orders->sum('revenue');
        $deliveredCount = (int) $orders->where('status', 'completed')->sum('cnt');
        $cancelledCount = (int) $orders->where('status', 'cancelled')->sum('cnt');
        $successRate = $totalOrders > 0
            ? round(($deliveredCount / $totalOrders) * 100, 1)
            : 0.0;

        $lastOrder = \Modules\Commerce\Orders\Domain\Models\Order::where('customer_id', $customerId)
            ->whereNull('deleted_at')
            ->orderByDesc('order_date')
            ->value('order_date');

        return [
            'total_orders'    => $totalOrders,
            'delivered'       => $deliveredCount,
            'cancelled'       => $cancelledCount,
            'success_rate'    => $successRate,
            'lifetime_value'  => $totalRevenue,
            'last_order_date' => $lastOrder,
        ];
    }
}
