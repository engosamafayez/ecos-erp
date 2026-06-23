<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\DTO;

use App\Core\DTO\BaseDTO;
use Modules\Commerce\Channels\Domain\Enums\ChannelPlatform;

final class ChannelDTO extends BaseDTO
{
    public function __construct(
        public readonly string $company_id,
        public readonly string $name,
        public readonly ChannelPlatform $platform,
        public readonly string $store_url,
        public readonly bool $is_active = true,
        public readonly bool $sync_products = true,
        public readonly bool $sync_prices = true,
        public readonly bool $sync_stock = true,
        public readonly ?string $consumer_key = null,
        public readonly ?string $consumer_secret = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            company_id: (string) $data['company_id'],
            name: (string) $data['name'],
            platform: ChannelPlatform::from((string) $data['platform']),
            store_url: (string) $data['store_url'],
            is_active: (bool) ($data['is_active'] ?? true),
            sync_products: (bool) ($data['sync_products'] ?? true),
            sync_prices: (bool) ($data['sync_prices'] ?? true),
            sync_stock: (bool) ($data['sync_stock'] ?? true),
            consumer_key: self::nullableString($data, 'consumer_key'),
            consumer_secret: self::nullableString($data, 'consumer_secret'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function channelAttributes(): array
    {
        return [
            'company_id' => $this->company_id,
            'name' => $this->name,
            'platform' => $this->platform->value,
            'store_url' => $this->store_url,
            'is_active' => $this->is_active,
            'sync_products' => $this->sync_products,
            'sync_prices' => $this->sync_prices,
            'sync_stock' => $this->sync_stock,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public function credentialAttributes(): ?array
    {
        if ($this->consumer_key === null && $this->consumer_secret === null) {
            return null;
        }

        return [
            'consumer_key' => $this->consumer_key ?? '',
            'consumer_secret' => $this->consumer_secret ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
