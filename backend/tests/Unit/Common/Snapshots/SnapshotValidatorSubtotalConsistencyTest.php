<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Snapshots;

use Modules\Common\Snapshots\Application\Validators\SnapshotValidator;
use Modules\Common\Snapshots\Domain\Contracts\FinancialSnapshotProvider;
use Modules\Common\Snapshots\Domain\DTOs\FinancialLineSnapshotDTO;
use Modules\Common\Snapshots\Domain\Exceptions\SnapshotConsistencyException;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-V1-REMEDIATION-COLLABORATION-INTEGRITY-035D-R1 §5 — the missing subtotal-vs-lines
 * invariant. No database, no Laravel boot — SnapshotValidator takes a plain interface
 * (FinancialSnapshotProvider), so a minimal stub is enough to exercise it directly.
 */
final class SnapshotValidatorSubtotalConsistencyTest extends TestCase
{
    private function line(float $lineTotal): FinancialLineSnapshotDTO
    {
        return new FinancialLineSnapshotDTO(
            aggregateId: 'order-1',
            sourceLineId: 'line-1',
            productId: 'product-1',
            productSku: 'SKU-1',
            productName: 'Widget',
            quantity: 1.0,
            unitPriceAtSale: $lineTotal,
            regularPriceAtSale: null,
            salePriceAtSale: null,
            lineTotal: $lineTotal,
            rawMaterialCost: null,
            packagingCost: null,
            manufacturingCost: null,
            otherCost: null,
            recipeCost: null,
            unitCost: null,
            lineCost: null,
            targetMarginPercent: 0.0,
            bomId: null,
            bomVersionNumber: null,
            sourceRecipeVersion: null,
            priceReviewId: null,
            priceReviewApprovedAt: null,
            priceReviewApprovedBy: null,
            costSnapshot: null,
        );
    }

    /** @param  list<FinancialLineSnapshotDTO>  $lines */
    private function provider(float $subtotal, array $lines): FinancialSnapshotProvider
    {
        return new class($subtotal, $lines) implements FinancialSnapshotProvider
        {
            /** @param  list<FinancialLineSnapshotDTO>  $lines */
            public function __construct(private readonly float $subtotal, private readonly array $lines) {}

            public function getSubtotal(): float
            {
                return $this->subtotal;
            }

            public function getGrandTotal(): float
            {
                return $this->subtotal;
            }

            public function getDiscountAmount(): float
            {
                return 0.0;
            }

            public function getDiscountType(): ?string
            {
                return null;
            }

            public function getShippingCost(): float
            {
                return 0.0;
            }

            public function getDepositAmount(): float
            {
                return 0.0;
            }

            public function getRemainingBalance(): float
            {
                return 0.0;
            }

            public function getCurrency(): string
            {
                return 'EGP';
            }

            public function getPaymentMethod(): ?string
            {
                return null;
            }

            public function getCustomerId(): ?string
            {
                return null;
            }

            public function getCustomerName(): ?string
            {
                return null;
            }

            public function getBrandId(): ?string
            {
                return null;
            }

            public function getChannelId(): ?string
            {
                return null;
            }

            public function getChannelName(): ?string
            {
                return null;
            }

            public function getShippingRuleId(): ?string
            {
                return null;
            }

            public function getShippingRuleName(): ?string
            {
                return null;
            }

            public function getShippingZone(): ?string
            {
                return null;
            }

            public function getShippingOverrideApplied(): bool
            {
                return false;
            }

            public function getShippingOverrideBy(): ?string
            {
                return null;
            }

            /** @return FinancialLineSnapshotDTO[] */
            public function getLineItems(): array
            {
                return $this->lines;
            }

            public function getSnapshotCreatedBy(): ?string
            {
                return null;
            }

            public function getSnapshotAggregateId(): string
            {
                return 'order-1';
            }

            public function getSnapshotAggregateType(): string
            {
                return 'order';
            }

            public function getSnapshotCompanyId(): ?string
            {
                return 'company-1';
            }

            public function buildIntegrityCanonical(): string
            {
                return 'order-1|order';
            }
        };
    }

    public function test_a_snapshot_whose_subtotal_equals_the_exact_sum_of_its_lines_is_accepted(): void
    {
        $provider = $this->provider(30.0, [$this->line(10.0), $this->line(20.0)]);

        (new SnapshotValidator)->validateConsistency($provider);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_a_snapshot_within_one_cent_of_its_lines_sum_is_accepted(): void
    {
        // Independently-rounded lines summing to 29.995, subtotal rounded to 30.00 separately —
        // within the canonical one-cent tolerance this validator uses.
        $provider = $this->provider(30.00, [$this->line(10.005), $this->line(19.99)]);

        (new SnapshotValidator)->validateConsistency($provider);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_a_snapshot_whose_subtotal_does_not_reconcile_with_its_lines_is_rejected(): void
    {
        $provider = $this->provider(30.0, [$this->line(10.0), $this->line(10.0)]); // sums to 20, not 30

        $this->expectException(SnapshotConsistencyException::class);
        $this->expectExceptionMessageMatches('/does not reconcile/');

        (new SnapshotValidator)->validateConsistency($provider);
    }
}
