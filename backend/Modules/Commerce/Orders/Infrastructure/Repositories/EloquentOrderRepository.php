<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Models\Order;

final class EloquentOrderRepository implements OrderRepositoryInterface
{
    private const SORTABLE = ['order_number', 'order_date', 'status', 'total', 'created_at'];

    private const WITH = ['channel', 'customer', 'lines.product'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Order::query()->with(self::WITH);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $c) use ($search): void {
                        $c->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $channelId = trim((string) ($filters['channel_id'] ?? ''));
        if ($channelId !== '') {
            $query->where('channel_id', $channelId);
        }

        $customerId = trim((string) ($filters['customer_id'] ?? ''));
        if ($customerId !== '') {
            $query->where('customer_id', $customerId);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    public function findById(string $id): ?Order
    {
        return Order::query()->with(self::WITH)->find($id);
    }

    public function create(array $attributes, array $lines): Order
    {
        $order = Order::query()->create($attributes);
        $order->lines()->createMany($lines);

        return $this->findById((string) $order->id) ?? $order;
    }

    public function update(Order $order, array $attributes, array $lines): Order
    {
        $order->update($attributes);
        $order->lines()->delete();
        $order->lines()->createMany($lines);

        return $this->findById((string) $order->id) ?? $order->refresh();
    }

    public function delete(Order $order): void
    {
        $order->delete();
    }

    public function nextOrderNumber(): string
    {
        $last = Order::query()
            ->withTrashed()
            ->orderByRaw("CAST(REPLACE(order_number, 'ORD-', '') AS UNSIGNED) DESC")
            ->value('order_number');

        if ($last === null) {
            return 'ORD-00001';
        }

        $current = (int) str_replace('ORD-', '', (string) $last);

        return 'ORD-'.str_pad((string) ($current + 1), 5, '0', STR_PAD_LEFT);
    }
}
