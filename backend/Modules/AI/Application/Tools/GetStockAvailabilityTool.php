<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\Products\Domain\Services\ProductCommerceAvailabilityService;

/**
 * §19 — wraps ProductCommerceAvailabilityService, THE canonical "is this Product
 * commercially available" authority. Deliberately does not read a Finished
 * Good's own physical on_hand quantity — that authority's own business rule is
 * that Finished Good availability is manufacturing/recipe-derived, unconditionally;
 * this tool must never present a number that authority itself does not use.
 */
final class GetStockAvailabilityTool implements AIToolInterface
{
    public function __construct(private readonly ProductCommerceAvailabilityService $availability) {}

    public function name(): string
    {
        return 'get_stock_availability';
    }

    public function description(): string
    {
        return 'Reports whether one product is currently available to sell.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'string'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function permission(): string
    {
        return 'inventory.products.view';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::Read;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $productId = is_string($input['product_id'] ?? null) ? $input['product_id'] : null;

        if ($productId === null || $productId === '') {
            return AIToolResult::invalidInput('product_id is required.');
        }

        $product = Product::query()->where('company_id', $context->companyId)->find($productId);

        if ($product === null) {
            return AIToolResult::notFound('No such product in your company.');
        }

        return AIToolResult::success([
            'product_name' => $product->name,
            'product_type' => $product->product_type,
            'is_available' => $this->availability->isAvailable($product),
        ]);
    }
}
