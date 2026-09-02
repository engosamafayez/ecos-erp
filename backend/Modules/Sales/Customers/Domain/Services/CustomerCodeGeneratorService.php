<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Domain\Services;

use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;

/**
 * Generates sequential Customer Codes in the format CUST-000001.
 * The sequence is per-company; each company has its own counter — mirrors
 * BrandCodeGeneratorService/BusinessAccountCodeGeneratorService/
 * TeamCodeGeneratorService exactly.
 */
final class CustomerCodeGeneratorService
{
    public function __construct(private readonly CustomerRepositoryInterface $customers) {}

    public function next(string $companyId): string
    {
        $number = $this->customers->nextCodeNumber($companyId);

        return sprintf('CUST-%06d', $number);
    }
}
