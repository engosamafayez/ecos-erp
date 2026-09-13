<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Infrastructure\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Commerce\Channels\Domain\Enums\ChannelPlatform;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Organization\Brands\Domain\Models\Brand;

/**
 * @extends Factory<Channel>
 */
final class ChannelFactory extends Factory
{
    /**
     * @var class-string<Channel>
     */
    protected $model = Channel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => $this->faker->company().' Store',
            'platform' => $this->faker->randomElement(ChannelPlatform::cases())->value,
            'store_url' => $this->faker->url(),
            // TASK-...-CONSOLIDATED-REMEDIATION-001 §9 — deterministic, matching the
            // production default (channels table migration: is_active default(true)).
            // A random default made any test that didn't explicitly pin this flaky
            // whenever it asserted an is_active-dependent count. Tests that specifically
            // need an inactive channel must request it explicitly via ->state([...]).
            'is_active' => true,
            'sync_products' => $this->faker->boolean(90),
            'sync_prices' => $this->faker->boolean(90),
            'sync_stock' => $this->faker->boolean(90),
            'last_sync_at' => null,
        ];
    }
}
