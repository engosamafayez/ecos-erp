<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * How money was taken at a stop. Drives the settlement split.
 *
 * This enum is the SINGLE canonical authority for the driver's ACTUAL collection channel. It is a
 * different fact from an order's payment INTENT (`orders.payment_method` /
 * `payment_method_manual`), and the two are never conflated: an order may declare InstaPay and
 * still be paid in cash at the door, or have been settled before dispatch. Settlement always reads
 * the actual collection fact recorded here.
 *
 * `already_paid` is the odd one out and stays that way: it marks value settled BEFORE the driver
 * carried any collection responsibility, so it is deliberately NOT a "the customer just paid me"
 * channel. Everything else is money the driver actually took during custody. That distinction is
 * expressed by {@see self::isDriverCollected()} rather than by re-listing types at each call site.
 *
 * TASK-ECOS-DISTRIBUTION-DRIVER-COLLECTION-CHANNELS-003 added `instapay` and `wallet` as canonical
 * driver-collected channels. Before that they were unrepresentable, so an InstaPay collection was
 * stored as `bank_transfer` and could not be told apart from any other transfer. Historical rows
 * are NOT reinterpreted: no `bank_transfer` or `card` row is backfilled to the new channels, since
 * an order's payment-method label is not evidence of how the driver was actually paid.
 *
 * The storage column is a plain `string(20)`, so the values below are the only constraint — which
 * is why classification lives here and every aggregate derives from it.
 */
enum PaymentType: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case InstaPay = 'instapay';
    case Wallet = 'wallet';
    case AlreadyPaid = 'already_paid';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::Card => 'Card / POS',
            self::InstaPay => 'InstaPay',
            self::Wallet => 'Wallet',
            self::AlreadyPaid => 'Already Paid',
        };
    }

    /**
     * Only cash physically travels with the driver, so only cash is reconciled
     * against what they hand back at settlement.
     *
     * Deliberately unchanged by the channel extension: InstaPay and Wallet are electronic, so they
     * are collected but are not cash the driver hands over.
     */
    public function isPhysicalCash(): bool
    {
        return $this === self::Cash;
    }

    /**
     * Did the DRIVER actually take this money during custody?
     *
     * True for every real collection channel; false only for `already_paid`, which records
     * pre-delivery settlement and was never the driver's to collect. This is the one place that
     * decision is made — aggregates ask this instead of hardcoding a type list, so a future channel
     * joins every total by being added to this enum.
     */
    public function isDriverCollected(): bool
    {
        return $this !== self::AlreadyPaid;
    }

    /** Driver-collected but not physical cash: bank transfer, card, InstaPay, wallet. */
    public function isElectronic(): bool
    {
        return $this->isDriverCollected() && ! $this->isPhysicalCash();
    }

    /**
     * The channels a driver may record as "the customer paid me now".
     *
     * Excludes `already_paid`, which is a pre-delivery fact rather than a collection channel.
     *
     * @return list<self>
     */
    public static function driverCollectedCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isDriverCollected()));
    }

    /** @return list<self> */
    public static function electronicCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isElectronic()));
    }

    /** @return list<string> */
    public static function driverCollectedValues(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::driverCollectedCases());
    }

    /** @return list<string> */
    public static function electronicValues(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::electronicCases());
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, driver_collected: bool, electronic: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $c) => [
                'value' => $c->value,
                'label' => $c->label(),
                // Lets a client group "collected now" apart from the pre-delivery marker without
                // re-encoding the rule.
                'driver_collected' => $c->isDriverCollected(),
                'electronic' => $c->isElectronic(),
            ],
            self::cases(),
        );
    }
}
