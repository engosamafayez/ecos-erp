<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce\Synchronization;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Synchronization\Application\Services\EcosOrderStatusToWooTranslator;
use PHPUnit\Framework\TestCase;

/**
 * TASK-...-025 (P0) — proves the FIXED outbound ECOS→Woo status map against the CURRENT
 * canonical OrderStatus (ADR-042 V3), the exact defect TASK-...-024 found (the previous inline
 * map's keys — 'pending'/'processing'/'completed' — matched no current OrderStatus value).
 *
 * Plain PHPUnit\TestCase — EcosOrderStatusToWooTranslator has no container dependency, so this
 * runs without a database or the HTTP surface, same discipline as OrderStatusV3ContractTest.
 */
final class EcosOrderStatusToWooTranslatorTest extends TestCase
{
    private EcosOrderStatusToWooTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new EcosOrderStatusToWooTranslator();
    }

    public function test_delivered_maps_to_completed(): void
    {
        self::assertSame('completed', $this->translator->translate(OrderStatus::Delivered));
    }

    public function test_cancelled_still_maps_to_cancelled(): void
    {
        // The ONE key that survived from the old (broken) map — must still work.
        self::assertSame('cancelled', $this->translator->translate(OrderStatus::Cancelled));
    }

    public function test_returned_maps_to_refunded(): void
    {
        self::assertSame('refunded', $this->translator->translate(OrderStatus::Returned));
    }

    public function test_active_in_progress_states_map_to_processing(): void
    {
        self::assertSame('processing', $this->translator->translate(OrderStatus::InProgress));
        self::assertSame('processing', $this->translator->translate(OrderStatus::Confirmed));
    }

    public function test_on_hold_and_awaiting_payment_both_map_to_woo_on_hold(): void
    {
        self::assertSame('on-hold', $this->translator->translate(OrderStatus::OnHold));
        self::assertSame('on-hold', $this->translator->translate(OrderStatus::AwaitingPayment));
    }

    /**
     * TASK-...-025 P0 — "do not invent mappings for ECOS states that should not propagate to
     * Woo": no core Woo status corresponds to ECOS's own dispatch/delivery/stock/scheduling
     * granularity, so these are intentionally unmapped and MUST be observable (null), never
     * silently coerced to some default success value.
     */
    public function test_intentionally_unmapped_states_return_null(): void
    {
        self::assertNull($this->translator->translate(OrderStatus::ReadyForDispatch));
        self::assertNull($this->translator->translate(OrderStatus::OutForDelivery));
        self::assertNull($this->translator->translate(OrderStatus::AwaitingStock));
        self::assertNull($this->translator->translate(OrderStatus::Scheduled));
    }

    public function test_every_order_status_case_is_explicitly_decided(): void
    {
        // No case should be silently missing from consideration — each one is either mapped or
        // explicitly, deliberately unmapped (the four asserted above).
        $deliberatelyUnmapped = [
            OrderStatus::ReadyForDispatch,
            OrderStatus::OutForDelivery,
            OrderStatus::AwaitingStock,
            OrderStatus::Scheduled,
        ];

        foreach (OrderStatus::cases() as $status) {
            $mapped = $this->translator->hasMapping($status);
            $expectedUnmapped = in_array($status, $deliberatelyUnmapped, true);

            self::assertSame(
                ! $expectedUnmapped,
                $mapped,
                "OrderStatus::{$status->name} mapping presence does not match the deliberate design.",
            );
        }
    }

    public function test_old_broken_map_keys_are_gone(): void
    {
        // TASK-...-024's exact finding: the previous map was keyed on 'pending'/'processing'/
        // 'completed' — strings that are NOT valid OrderStatus backing values. Guard against
        // ever reintroducing that class of defect.
        foreach (['pending', 'processing', 'completed'] as $staleKey) {
            self::assertNull(
                OrderStatus::tryFrom($staleKey),
                "'{$staleKey}' must not be reintroduced as an OrderStatus backing value — "
                .'the P0 defect was exactly this string family being mistaken for canonical statuses.',
            );
        }
    }
}
