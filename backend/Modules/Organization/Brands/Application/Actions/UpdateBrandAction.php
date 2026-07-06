<?php

declare(strict_types=1);

namespace Modules\Organization\Brands\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Brands\Application\DTO\BrandDTO;
use Modules\Organization\Brands\Domain\Contracts\BrandRepositoryInterface;
use Modules\Organization\Brands\Domain\Exceptions\BrandNotFoundException;

final class UpdateBrandAction extends BaseAction
{
    public function __construct(private readonly BrandRepositoryInterface $brands) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = $arguments[0] ?? null;
        $dto = $arguments[1] ?? null;

        if (! is_string($id) || ! $dto instanceof BrandDTO) {
            throw new InvalidArgumentException('UpdateBrandAction::execute expects (string $id, BrandDTO $dto).');
        }

        $brand = $this->brands->findById($id);
        if ($brand === null) {
            throw new BrandNotFoundException($id);
        }

        $slug = $dto->slug ?? Str::slug($dto->name);
        $baseSlug = $slug;
        $counter = 1;
        while ($this->brands->existsBySlug($brand->company_id, $slug, $id)) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $attributes = [
            'name'                  => $dto->name,
            'slug'                  => $slug,
            'logo'                  => $dto->logo,
            'description'           => $dto->description,
            'is_active'             => $dto->is_active,
            'default_target_margin' => $dto->default_target_margin,
            'default_markup'        => $dto->default_markup,
            'default_discount_pct'  => $dto->default_discount_pct,
        ];

        // Code can only be overridden if explicitly provided
        if ($dto->code !== null) {
            $attributes['code'] = $dto->code;
        }

        $brand = $this->brands->update($brand, $attributes);

        return OperationResult::success($brand, 'Brand updated successfully.');
    }
}
