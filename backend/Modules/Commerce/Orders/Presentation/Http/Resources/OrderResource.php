<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Commerce\Orders\Domain\Models\Order;

/**
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'channel' => $this->whenLoaded('channel', fn () => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
            ]),
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'code' => $this->customer->code,
            ]),
            'external_order_id' => $this->external_order_id,
            'order_number' => $this->order_number,
            'order_date' => $this->order_date?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'subtotal' => (float) $this->subtotal,
            'shipping_total' => (float) $this->shipping_total,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'notes' => $this->notes,
            'customer_note' => $this->customer_note,
            'billing_first_name' => $this->billing_first_name,
            'billing_last_name' => $this->billing_last_name,
            'billing_company' => $this->billing_company,
            'billing_country' => $this->billing_country,
            'billing_state' => $this->billing_state,
            'billing_city' => $this->billing_city,
            'billing_address_1' => $this->billing_address_1,
            'billing_address_2' => $this->billing_address_2,
            'billing_postcode' => $this->billing_postcode,
            'billing_phone' => $this->billing_phone,
            'billing_email' => $this->billing_email,
            'shipping_first_name' => $this->shipping_first_name,
            'shipping_last_name' => $this->shipping_last_name,
            'shipping_company' => $this->shipping_company,
            'shipping_country' => $this->shipping_country,
            'shipping_state' => $this->shipping_state,
            'shipping_city' => $this->shipping_city,
            'shipping_address_1' => $this->shipping_address_1,
            'shipping_address_2' => $this->shipping_address_2,
            'shipping_postcode' => $this->shipping_postcode,
            'payment_method' => $this->payment_method,
            'payment_method_title' => $this->payment_method_title,
            'transaction_id' => $this->transaction_id,
            'date_paid' => $this->date_paid?->toIso8601String(),
            'shipping_method' => $this->shipping_method,
            'fees' => $this->whenLoaded('fees', fn () => $this->fees->map(fn ($fee) => [
                'id' => $fee->id,
                'name' => $fee->name,
                'total' => (float) $fee->total,
            ])),
            'coupons' => $this->whenLoaded('coupons', fn () => $this->coupons->map(fn ($coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'discount' => (float) $coupon->discount,
            ])),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product' => $line->relationLoaded('product') ? [
                    'id' => $line->product->id,
                    'sku' => $line->product->sku,
                    'name' => $line->product->name,
                    'image_url' => $line->product->image_url,
                ] : null,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
