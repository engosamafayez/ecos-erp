<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Inventory\Products\Application\DTO\ProductDTO;
use Modules\Inventory\Products\Domain\Contracts\ProductRepositoryInterface;
use Modules\Inventory\Products\Domain\Services\SkuGenerator;

/**
 * Creates a new product.
 */
final class CreateProductAction extends BaseAction
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly SkuGenerator $skuGenerator,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects a single {@see ProductDTO}.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof ProductDTO) {
            throw new InvalidArgumentException('CreateProductAction::execute expects a ProductDTO.');
        }

        $attributes = $dto->toArray();

        // Ownership is ALWAYS derived from the authenticated actor — never from the
        // client or the DTO (Decision 2). This is the single tenant authority.
        $companyId = Auth::user()?->company_id !== null ? (string) Auth::user()->company_id : null;
        $attributes['company_id'] = $companyId;

        // Generate an authoritative, company-scoped, globally-unique SKU when none
        // was supplied (Decision 1). A client-supplied SKU is preserved as-is and
        // remains globally unique via the DB constraint.
        if (empty($attributes['sku'])) {
            if ($companyId === null) {
                throw new InvalidArgumentException(
                    'Cannot generate a SKU: the authenticated actor has no owning company (company_id).',
                );
            }
            $attributes['sku'] = $this->skuGenerator->generate($companyId, (string) $dto->product_type);
        }

        $product = $this->products->create($attributes);

        return OperationResult::success($product, 'Product created successfully.');
    }
}
