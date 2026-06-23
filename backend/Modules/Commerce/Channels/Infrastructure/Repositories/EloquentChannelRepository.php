<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Models\Channel;

final class EloquentChannelRepository implements ChannelRepositoryInterface
{
    private const SORTABLE = ['name', 'platform', 'is_active', 'last_sync_at', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Channel::query()->with('company');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('store_url', 'like', "%{$search}%");
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $platform = trim((string) ($filters['platform'] ?? ''));
        if ($platform !== '') {
            $query->where('platform', $platform);
        }

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    public function findById(string $id): ?Channel
    {
        return Channel::query()->with('company')->find($id);
    }

    public function create(array $attributes, ?array $credentials): Channel
    {
        $channel = Channel::query()->create($attributes);

        if ($credentials !== null) {
            $channel->credential()->create($credentials);
        }

        return $channel->refresh()->load('company');
    }

    public function update(Channel $channel, array $attributes, ?array $credentials): Channel
    {
        $channel->update($attributes);

        if ($credentials !== null) {
            $channel->credential()->updateOrCreate([], $credentials);
        }

        return $channel->refresh()->load('company');
    }

    public function delete(Channel $channel): void
    {
        $channel->delete();
    }
}
