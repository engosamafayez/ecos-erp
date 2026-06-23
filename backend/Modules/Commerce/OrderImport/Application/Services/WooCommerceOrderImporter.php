<?php

declare(strict_types=1);

namespace Modules\Commerce\OrderImport\Application\Services;

use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\OrderImport\Application\DTO\OrderImportResultDTO;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Sales\Customers\Domain\Models\Customer;
use Throwable;

final class WooCommerceOrderImporter
{
    private const PER_PAGE = 100;

    private const TIMEOUT = 30;

    private const STATUS_MAP = [
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        'on-hold' => 'pending',
        'refunded' => 'cancelled',
        'failed' => 'cancelled',
    ];

    public function __construct(private readonly OrderRepositoryInterface $orders) {}

    public function import(Channel $channel): OrderImportResultDTO
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return new OrderImportResultDTO(0, 0, 0, 0, 0, 0, ['No credentials configured for this channel.']);
        }

        $importedOrders = 0;
        $createdCustomers = 0;
        $createdOrders = 0;
        $createdLines = 0;
        $skippedOrders = 0;
        $failedLines = 0;
        $errors = [];

        $page = 1;
        $baseUrl = rtrim($channel->store_url, '/') . '/wp-json/wc/v3/orders';

        while (true) {
            try {
                $response = Http::withBasicAuth($credential->consumer_key, $credential->consumer_secret)
                    ->timeout(self::TIMEOUT)
                    ->get($baseUrl, ['per_page' => self::PER_PAGE, 'page' => $page]);

                if (! $response->successful()) {
                    $errors[] = "Failed to fetch page {$page}: HTTP {$response->status()}.";
                    break;
                }

                /** @var list<array<string, mixed>> $wooOrders */
                $wooOrders = $response->json() ?? [];

                if (empty($wooOrders)) {
                    break;
                }

                foreach ($wooOrders as $wooOrder) {
                    $importedOrders++;
                    $externalId = (string) ($wooOrder['id'] ?? '');

                    if ($externalId !== '' && $this->orderExists($externalId, (string) $channel->id)) {
                        $skippedOrders++;
                        $importedOrders--;
                        continue;
                    }

                    try {
                        [$customer, $wasCreated] = $this->resolveCustomer($wooOrder);

                        if ($wasCreated) {
                            $createdCustomers++;
                        }

                        [$order, $lines, $lineFails, $lineErrors] = $this->buildOrder(
                            $wooOrder,
                            $channel,
                            $customer,
                        );

                        $failedLines += $lineFails;
                        $errors = array_merge($errors, $lineErrors);

                        if ($lines !== []) {
                            $subtotal = array_sum(array_column($lines, 'line_total'));
                            $order['subtotal'] = $subtotal;
                            $order['total'] = $subtotal;

                            $this->orders->create($order, $lines);
                            $createdOrders++;
                            $createdLines += count($lines);
                        } else {
                            $skippedOrders++;
                            $importedOrders--;
                            $errors[] = "Order #{$externalId} skipped: no valid line items.";
                        }
                    } catch (Throwable $e) {
                        $importedOrders--;
                        $errors[] = "Failed to import order #{$externalId}: {$e->getMessage()}";
                    }
                }

                $totalPages = max(1, (int) ($response->header('X-WP-TotalPages') ?: 1));

                if ($page >= $totalPages || count($wooOrders) < self::PER_PAGE) {
                    break;
                }

                $page++;
            } catch (Throwable $e) {
                $errors[] = "Request error on page {$page}: {$e->getMessage()}";
                break;
            }
        }

        return new OrderImportResultDTO(
            $importedOrders,
            $createdCustomers,
            $createdOrders,
            $createdLines,
            $skippedOrders,
            $failedLines,
            $errors,
        );
    }

    private function orderExists(string $externalId, string $channelId): bool
    {
        return Order::query()
            ->where('external_order_id', $externalId)
            ->where('channel_id', $channelId)
            ->exists();
    }

    /**
     * Normalize a phone number to E.164-like digits (no +).
     * Example: 01012345678 → 201012345678
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            $digits = '2' . $digits;
        }

        return $digits;
    }

    /**
     * Find or create a customer from WooCommerce billing data.
     *
     * @param  array<string, mixed>  $wooOrder
     * @return array{Customer, bool}
     */
    private function resolveCustomer(array $wooOrder): array
    {
        /** @var array<string, string> $billing */
        $billing = is_array($wooOrder['billing'] ?? null) ? $wooOrder['billing'] : [];

        $rawPhone = trim((string) ($billing['phone'] ?? ''));
        $email = trim((string) ($billing['email'] ?? ''));
        $normalizedPhone = $rawPhone !== '' ? $this->normalizePhone($rawPhone) : '';

        // 1. Match by phone
        if ($normalizedPhone !== '') {
            $customer = Customer::query()
                ->where('phone', $normalizedPhone)
                ->orWhere('mobile', $normalizedPhone)
                ->first();

            if ($customer !== null) {
                return [$customer, false];
            }
        }

        // 2. Match by email
        if ($email !== '') {
            $customer = Customer::query()->where('email', $email)->first();

            if ($customer !== null) {
                return [$customer, false];
            }
        }

        // 3. Create new customer
        $firstName = trim((string) ($billing['first_name'] ?? ''));
        $lastName = trim((string) ($billing['last_name'] ?? ''));
        $name = trim("{$firstName} {$lastName}");

        if ($name === '') {
            $name = $email !== '' ? $email : 'WooCommerce Customer';
        }

        $customer = Customer::query()->create([
            'code' => $this->nextCustomerCode(),
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $normalizedPhone !== '' ? $normalizedPhone : null,
            'city' => trim((string) ($billing['city'] ?? '')) ?: null,
            'country' => trim((string) ($billing['country'] ?? '')) ?: null,
            'address' => trim((string) ($billing['address_1'] ?? '')) ?: null,
            'is_active' => true,
        ]);

        return [$customer, true];
    }

    /**
     * Build the order attributes array and resolved line items.
     *
     * @param  array<string, mixed>  $wooOrder
     * @return array{array<string, mixed>, list<array<string, mixed>>, int, list<string>}
     */
    private function buildOrder(array $wooOrder, Channel $channel, Customer $customer): array
    {
        $externalId = (string) ($wooOrder['id'] ?? '');
        $wooNumber = (string) ($wooOrder['number'] ?? $externalId);
        $wooStatus = (string) ($wooOrder['status'] ?? 'pending');
        $status = self::STATUS_MAP[$wooStatus] ?? 'pending';

        $dateCreated = (string) ($wooOrder['date_created'] ?? '');
        $orderDate = $dateCreated !== '' ? substr($dateCreated, 0, 10) : now()->toDateString();

        $notes = trim((string) ($wooOrder['customer_note'] ?? ''));

        $orderAttributes = [
            'channel_id' => (string) $channel->id,
            'customer_id' => (string) $customer->id,
            'external_order_id' => $externalId,
            'order_number' => $this->orders->nextOrderNumber(),
            'order_date' => $orderDate,
            'status' => OrderStatus::from($status)->value,
            'subtotal' => 0,
            'total' => 0,
            'notes' => $notes !== '' ? "WC#{$wooNumber}: {$notes}" : "Imported from WooCommerce order #{$wooNumber}.",
        ];

        /** @var list<array<string, mixed>> $rawLineItems */
        $rawLineItems = is_array($wooOrder['line_items'] ?? null) ? $wooOrder['line_items'] : [];

        $lines = [];
        $failedLines = 0;
        $lineErrors = [];

        foreach ($rawLineItems as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));

            if ($sku === '') {
                $failedLines++;
                $lineErrors[] = "Order #{$externalId} line skipped: no SKU (product_id={$item['product_id']}).";
                continue;
            }

            $product = Product::query()->where('sku', $sku)->first();

            if ($product === null) {
                $failedLines++;
                $lineErrors[] = "Order #{$externalId} line skipped: SKU [{$sku}] not found in ECOS.";
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['price'] ?? 0);
            $lineTotal = (float) ($item['total'] ?? $quantity * $unitPrice);

            $lines[] = [
                'product_id' => (string) $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return [$orderAttributes, $lines, $failedLines, $lineErrors];
    }

    private function nextCustomerCode(): string
    {
        $last = Customer::query()
            ->withTrashed()
            ->where('code', 'like', 'CUS-%')
            ->orderByRaw("CAST(REPLACE(code, 'CUS-', '') AS UNSIGNED) DESC")
            ->value('code');

        if ($last === null) {
            return 'CUS-001';
        }

        $current = (int) str_replace('CUS-', '', (string) $last);

        return 'CUS-'.str_pad((string) ($current + 1), 3, '0', STR_PAD_LEFT);
    }
}
