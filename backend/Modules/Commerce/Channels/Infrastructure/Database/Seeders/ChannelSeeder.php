<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Commerce\Channels\Domain\Enums\ChannelPlatform;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Organization\Companies\Domain\Models\Company;

final class ChannelSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('code', 'ECOS')->first();

        if ($company === null) {
            return;
        }

        $channels = [
            [
                'name' => 'ECOS Main Store',
                'platform' => ChannelPlatform::WooCommerce->value,
                'store_url' => 'https://store.ecos.example.com',
                'sync_products' => true,
                'sync_prices' => true,
                'sync_stock' => true,
                'consumer_key' => 'ck_sample_main_store_key',
                'consumer_secret' => 'cs_sample_main_store_secret',
            ],
            [
                'name' => 'ECOS Wholesale',
                'platform' => ChannelPlatform::WooCommerce->value,
                'store_url' => 'https://wholesale.ecos.example.com',
                'sync_products' => true,
                'sync_prices' => true,
                'sync_stock' => true,
                'consumer_key' => 'ck_sample_wholesale_key',
                'consumer_secret' => 'cs_sample_wholesale_secret',
            ],
        ];

        foreach ($channels as $data) {
            $channel = Channel::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'name' => $data['name'],
                ],
                [
                    'platform' => $data['platform'],
                    'store_url' => $data['store_url'],
                    'is_active' => true,
                    'sync_products' => $data['sync_products'],
                    'sync_prices' => $data['sync_prices'],
                    'sync_stock' => $data['sync_stock'],
                ],
            );

            ChannelCredential::updateOrCreate(
                ['channel_id' => $channel->id],
                [
                    'consumer_key' => $data['consumer_key'],
                    'consumer_secret' => $data['consumer_secret'],
                ],
            );
        }
    }
}
