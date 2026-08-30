<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Enums;

/**
 * Trip lifecycle.
 *
 * Preserves the 13 states accumulated by the previous Distribution work
 * (TASK-DIST-003/004A/004B/005), which were spread across four PostgreSQL
 * CHECK-constraint migrations. They are consolidated here into one authority,
 * with the transition table making illegal moves unreachable.
 *
 * Flow: planning → loading → loading_completed → driver_accepted
 *       → ready_for_dispatch → dispatched → in_progress/out_for_delivery
 *       → completed → settlement_pending → closed
 */
enum TripStatus: string
{
    case Planning = 'planning';
    case Loading = 'loading';
    case LoadingCompleted = 'loading_completed';
    case DriverAccepted = 'driver_accepted';
    case DispatchBlocked = 'dispatch_blocked';
    case ReadyForDispatch = 'ready_for_dispatch';
    case Dispatched = 'dispatched';
    case OutForDelivery = 'out_for_delivery';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case SettlementPending = 'settlement_pending';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Loading => 'Loading',
            self::LoadingCompleted => 'Loading Completed',
            self::DriverAccepted => 'Driver Accepted',
            self::DispatchBlocked => 'Dispatch Blocked',
            self::ReadyForDispatch => 'Ready for Dispatch',
            self::Dispatched => 'Dispatched',
            self::OutForDelivery => 'Out for Delivery',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::SettlementPending => 'Settlement Pending',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planning => [self::Loading, self::Cancelled],
            self::Loading => [self::LoadingCompleted, self::Planning, self::Cancelled],
            self::LoadingCompleted => [self::DriverAccepted, self::DispatchBlocked, self::Loading, self::Cancelled],
            self::DriverAccepted => [self::ReadyForDispatch, self::DispatchBlocked, self::Cancelled],
            self::DispatchBlocked => [self::LoadingCompleted, self::DriverAccepted, self::Cancelled],
            self::ReadyForDispatch => [self::Dispatched, self::DispatchBlocked, self::Cancelled],
            self::Dispatched => [self::OutForDelivery, self::InProgress, self::Cancelled],
            self::OutForDelivery => [self::InProgress, self::Completed],
            self::InProgress => [self::Completed, self::OutForDelivery],
            self::Completed => [self::SettlementPending, self::Closed],
            self::SettlementPending => [self::Closed],
            self::Closed => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /**
     * The status VALUES a trip can hold while it still CONSUMES its driver and
     * vehicle — i.e. every state that is not terminal.
     *
     * Derived from isTerminal() rather than hand-listed, so this can never drift
     * from the single terminal definition above. It exists for query builders,
     * which need the raw strings the enum casts to.
     *
     * @return list<string>
     */
    public static function nonTerminalValues(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => ! $c->isTerminal()),
        ));
    }

    /** Orders and custody may only be edited while the trip is still being built. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Planning, self::Loading], true);
    }

    /**
     * True once REAL goods custody has been handed to the Driver/Vehicle — i.e. loading is
     * complete (all loaded products driver-confirmed) and the operational custody cycle is open,
     * up to and including settlement-pending. This is the single canonical "operationally active
     * custody" gate for Driver Closing (TASK-...-SINGLE-ACTIVE-CUSTODY-CLOSURE-001): planning /
     * loading shells (isEditable) and terminal trips (isTerminal) are NOT custody. Derived from
     * the two, so it can never drift.
     */
    public function isCustodyEligible(): bool
    {
        return ! $this->isEditable() && ! $this->isTerminal();
    }

    /**
     * The status VALUES that represent an OPEN operational custody (for query builders).
     *
     * @return list<string>
     */
    public static function custodyEligibleValues(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => $c->isCustodyEligible()),
        ));
    }

    /** True once the trip has physically left the warehouse. */
    public function isOnTheRoad(): bool
    {
        return in_array($this, [self::Dispatched, self::OutForDelivery, self::InProgress], true);
    }

    /** Deliveries may only be recorded against a trip that is on the road. */
    public function acceptsDeliveryExecution(): bool
    {
        return $this->isOnTheRoad();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
