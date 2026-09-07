<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Cash\Domain\Models\CashAccount;
use Modules\Finance\Cash\Domain\Services\CashService;
use Modules\Finance\Integration\Domain\Services\AccountRoleResolver;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementDirection;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripCashHandover;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;

/**
 * Treasury's physical cash-handover confirmation for a trip settlement
 * (TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002).
 *
 * ┌─ DISTRIBUTION OWNS THE OPERATIONAL FACT; FINANCE OWNS THE POSTING ──────┐
 * │ This service never writes a journal and never touches the general       │
 * │ ledger. It computes the operational facts (expected cash, the driver's  │
 * │ declaration, the difference) and hands the ONE number that is ever      │
 * │ financially real — the amount Treasury actually counted — to the        │
 * │ canonical {@see CashService}, which alone talks to the Posting          │
 * │ Coordinator / Journal Engine. No new ledger, no new wallet, no direct   │
 * │ journal write here (Architecture-001 §3/§9).                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHY THE LOCK COMES BEFORE THE FINANCE CALL, NOT AFTER ─────────────────┐
 * │ {@see CashService::recordTransaction()} mints its OWN random uuid as the │
 * │ Posting Coordinator's idempotency key on every call — it exposes no      │
 * │ parameter for a caller-supplied idempotency key, so calling it twice for │
 * │ the same logical handover would create TWO separate journals, not one    │
 * │ deduplicated one. The Finance-layer idempotency contract (Architecture-  │
 * │ 001 §11) is therefore not sufficient by itself for a REPEATED confirm    │
 * │ call at this altitude. Concurrency-safety here is therefore enforced the │
 * │ same way {@see \Modules\Operations\Loading\Application\Actions\ReceiveVehicleReturnAction}  │
 * │ enforces it: {@see TripSettlement}::lockForUpdate() is taken FIRST,      │
 * │ inside the transaction, before the existing-handover check and before    │
 * │ CashService is ever called — so a concurrent second request BLOCKS on    │
 * │ the lock and, once unblocked, sees the first request's row and resolves  │
 * │ idempotently rather than posting a second time. The unique index on      │
 * │ trip_settlement_id (the migration) is the last-resort database backstop, │
 * │ not the primary guarantee.                                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CashHandoverService
{
    /**
     * Per-company configuration, resolved through the existing, generic
     * {@see AccountRoleResolver} — the SAME mechanism {@see \Modules\Finance\Integration\Domain\Services\CommercialAccountingService}
     * uses for 'cod_clearing' / 'sales_revenue' / 'vat_output'. Adding this role
     * requires ONLY a data row in `finance_account_roles` per company — no schema
     * change — and an unmapped role fails loudly (FinanceException::accountRoleNotMapped)
     * rather than guessing an account.
     */
    private const DRIVER_CASH_CLEARING_ROLE = 'driver_cash_clearing';

    /** Matches TripSettlement::calculateDiscrepancy()'s own tolerance convention. */
    private const EPSILON = 0.005;

    public function __construct(
        private readonly CashService $cash,
        private readonly AccountRoleResolver $roles,
    ) {}

    /**
     * The System Expected Cash for one trip — the canonical Net Cash formula
     * ("physical cash collected + approved cash-in − approved cash-out",
     * {@see \Modules\Logistics\Distribution\Domain\Services\DriverDaySettlementReadService},
     * TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §14), scoped to ONE
     * trip rather than a whole driver-day.
     *
     * Computed independently here — a direct query over the same canonical
     * {@see DriverTripMovement} rows and the same {@see TripSettlement::$cash_collected}
     * field the read-service uses — rather than by calling a private method on
     * that read-service file. That file is large, shared, and may be under
     * concurrent edit by the parallel Post-Driver-Return session (Architecture-
     * 001 §16); this keeps Cash Handover's only dependency on it to "reads the
     * same public columns," never "depends on its internals."
     */
    public function expectedCash(Trip $trip): float
    {
        $cashCollected = (float) ($trip->settlement?->cash_collected ?? 0.0);

        $movements = DriverTripMovement::query()
            ->where('trip_id', $trip->id)
            ->get(['direction', 'amount', 'status']);

        $cashIn = 0.0;
        $cashOut = 0.0;
        foreach ($movements as $movement) {
            $status = $movement->status instanceof DriverTripMovementStatus
                ? $movement->status
                : DriverTripMovementStatus::from((string) $movement->status);

            if (! $status->countsTowardTotals()) {
                continue;
            }

            $direction = $movement->direction instanceof DriverTripMovementDirection
                ? $movement->direction
                : DriverTripMovementDirection::from((string) $movement->direction);

            if ($direction === DriverTripMovementDirection::CashIn) {
                $cashIn += (float) $movement->amount;
            } else {
                $cashOut += (float) $movement->amount;
            }
        }

        return round($cashCollected + $cashIn - $cashOut, 2);
    }

    /**
     * Confirm Treasury's physical receipt of a driver's handed-back cash for
     * one trip settlement.
     *
     * Idempotent and concurrency-safe (§11/§12 of the implementation task): a
     * repeat confirmation with the SAME received amount resolves to the
     * existing row with no second Finance call; a CONFLICTING amount is
     * refused; two concurrent confirmations for the same settlement serialize
     * on the settlement row lock, so only one ever reaches CashService.
     *
     * Posts ONLY $receivedCash — never the expected amount, never the
     * driver's declaration (§13/§22: no automatic driver liability is created
     * here; a shortfall is recorded and left for the existing, separate
     * approval/investigation authorities Architecture-001 §12 identified).
     */
    public function confirmReceipt(
        TripSettlement $settlement,
        float $receivedCash,
        string $cashAccountUuid,
        int $receiverId,
        ?string $notes = null,
    ): TripCashHandover {
        if ($receivedCash < 0.0) {
            throw DistributionException::cashHandoverAmountInvalid();
        }

        return DB::transaction(function () use ($settlement, $receivedCash, $cashAccountUuid, $receiverId, $notes): TripCashHandover {
            // Lock FIRST — before the idempotency check and before any Finance call.
            // Mirrors ReceiveVehicleReturnAction's lockForUpdate-before-check ordering.
            $locked = TripSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            $existing = TripCashHandover::query()->where('trip_settlement_id', $locked->id)->first();
            if ($existing !== null) {
                return $this->assertSameOrRefuse($existing, $receivedCash);
            }

            $trip = $locked->trip;
            if ($trip === null) {
                throw DistributionException::cashHandoverTripMissing();
            }
            $companyId = (string) $trip->company_id;

            $account = CashAccount::query()
                ->where('company_id', $companyId)
                ->where('uuid', $cashAccountUuid)
                ->where('is_active', true)
                ->first();
            if ($account === null) {
                throw DistributionException::cashHandoverAccountInvalid();
            }

            $expected = $this->expectedCash($trip);
            $declared = $locked->driver_cash_submitted !== null ? (float) $locked->driver_cash_submitted : null;
            $difference = round($receivedCash - $expected, 2);

            // Reached by exactly one concurrent request per settlement (the lock
            // above). The physically received amount is the only figure posted.
            $transaction = $this->cash->recordTransaction(
                account: $account,
                type: 'receipt',
                amount: $receivedCash,
                counterpartyAccountId: $this->roles->resolve($companyId, self::DRIVER_CASH_CLEARING_ROLE),
                description: 'Driver cash handover — trip '.($trip->trip_number ?? $trip->uuid),
                actorId: $receiverId,
            );

            try {
                return TripCashHandover::create([
                    'company_id' => $companyId,
                    'trip_settlement_id' => $locked->id,
                    'trip_id' => $trip->id,
                    'driver_declared_cash' => $declared,
                    'expected_cash' => $expected,
                    'received_cash' => $receivedCash,
                    'difference' => $difference,
                    'cash_account_id' => $account->id,
                    'cash_transaction_id' => $transaction->id,
                    'received_by' => $receiverId,
                    'notes' => $notes,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Last-resort backstop (see class docblock) — the lock above should
                // make this unreachable in normal operation.
                $winner = TripCashHandover::query()->where('trip_settlement_id', $locked->id)->first();

                return $winner ?? throw DistributionException::cashHandoverRaceUnresolved();
            }
        });
    }

    private function assertSameOrRefuse(TripCashHandover $existing, float $receivedCash): TripCashHandover
    {
        if (abs((float) $existing->received_cash - $receivedCash) <= self::EPSILON) {
            return $existing; // same amount re-confirmed — no-op, no double posting
        }

        throw DistributionException::cashHandoverAlreadyConfirmed();
    }
}
