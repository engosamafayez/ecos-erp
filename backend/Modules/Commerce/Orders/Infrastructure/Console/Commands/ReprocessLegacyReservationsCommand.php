<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Infrastructure\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnToPendingWorkflow;
use Modules\Operations\Preparation\Application\Services\BranchAssignmentEngine;
use Modules\Operations\Preparation\Application\Services\CoverageResolutionService;
use Throwable;

/**
 * ADR-027 §15 H4 — Legacy Order Reprocessing.
 *
 * Recovers orders that were stranded by the pre-repair contract breach: a missing
 * warehouse used to be written as `status = awaiting_stock`, which
 *   (a) is reserved by ADR-027 §3 Case 4 for a FINISHED-GOOD shortage, and
 *   (b) removed the order from every status-keyed recovery path, permanently.
 *
 * DRY-RUN BY DEFAULT. Nothing is written unless --execute is passed.
 *
 * Each order is classified into exactly one bucket and the buckets are reported
 * separately, because they need different people:
 *
 *   RECOVERABLE          — assignment resolves. Re-runs BranchAssignmentEngine, whose
 *                          canonical WarehouseAssigned event drives the H3 listener,
 *                          which executes the postponed reservation. No status is
 *                          written by this command; the workflow owns that.
 *   OPERATOR_CORRECTION  — the order cannot be assigned from its own data (e.g. no
 *                          governorate). A human must complete the address. Guessing
 *                          it here would fabricate business data.
 *   STATUS_ONLY          — assignment still fails, but the order carries the illegal
 *                          `awaiting_stock` written for a warehouse blocker. The
 *                          lifecycle status is restored to its pre-breach value and
 *                          the reservation is moved to `pending` (ADR-027 §2), so the
 *                          order becomes visible to recovery again.
 *   NOT_ELIGIBLE         — terminal, or already healthy. Left alone.
 *
 * Idempotent: a second run finds nothing to do, because recovered orders no longer
 * match the selection criteria. Tenant-scoped via --company.
 */
final class ReprocessLegacyReservationsCommand extends Command
{
    protected $signature = 'orders:reprocess-legacy-reservations
        {--company= : Restrict to a single company_id (tenant scope)}
        {--order= : Restrict to a single order_number}
        {--execute : Actually apply changes. Omit for a dry run}';

    protected $description = 'ADR-027 H4 — supervised recovery of orders stranded without a warehouse assignment (dry-run by default).';

    /**
     * Statuses ADR-027 §2 treats as active commercial states (ADR-042 vocabulary).
     *
     * `confirmed` is deliberately EXCLUDED. Recovery composes
     * ReturnToPendingWorkflow → ProcessOrderWorkflow, and the first step unlocks
     * the order — so sweeping a confirmed order through here would silently
     * un-confirm it. Confirmed orders that still need their reservation executed
     * are handled by ExecuteReservationOnWarehouseAssigned (ADR-027 H3), which
     * runs ProcessOrderWorkflow directly and preserves Confirmed.
     */
    private const ACTIVE_STATUSES = [
        OrderStatus::InProgress,
        OrderStatus::AwaitingPayment,
        OrderStatus::AwaitingStock,
    ];

    public function __construct(
        private readonly BranchAssignmentEngine $branchAssignment,
        private readonly CoverageResolutionService $coverage,
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly ProcessOrderWorkflow $processWorkflow,
        private readonly ReturnToPendingWorkflow $returnToNewWorkflow,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        $candidates = $this->findCandidates();

        if ($candidates->isEmpty()) {
            $this->info('No stranded orders found. Nothing to do.');

            return Command::SUCCESS;
        }

        $this->line($execute
            ? '<fg=yellow>EXECUTE MODE — changes will be applied.</>'
            : '<fg=cyan>DRY RUN — no changes will be written. Pass --execute to apply.</>');
        $this->newLine();

        $rows = [];
        $counts = ['RECOVERABLE' => 0, 'OPERATOR_CORRECTION' => 0, 'STATUS_ONLY' => 0, 'NOT_ELIGIBLE' => 0];

        foreach ($candidates as $order) {
            [$class, $reason] = $this->classify($order);
            $counts[$class]++;

            $outcome = 'previewed';

            if ($execute && $class !== 'NOT_ELIGIBLE') {
                try {
                    $outcome = $this->apply($order, $class);
                } catch (Throwable $e) {
                    report($e);
                    $outcome = 'FAILED: '.$e->getMessage();
                }
            }

            $rows[] = [
                $order->order_number,
                $order->status->value,
                $order->reservation_status?->value ?? '—',
                $order->warehouse_assignment_source ?? '—',
                $class,
                $reason,
                $outcome,
            ];
        }

        $this->table(
            ['Order', 'Status', 'Reservation', 'Assign Source', 'Classification', 'Reason', 'Outcome'],
            $rows,
        );

        $this->newLine();
        foreach ($counts as $class => $n) {
            $this->line(sprintf('  %-20s %d', $class, $n));
        }

        if (! $execute) {
            $this->newLine();
            $this->comment('Dry run complete. Re-run with --execute to apply.');
        }

        return Command::SUCCESS;
    }

    /** @return Collection<int, Order> */
    private function findCandidates(): Collection
    {
        return Order::query()
            ->whereNull('deleted_at')
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::ACTIVE_STATUSES))
            ->whereNull('assigned_warehouse_id')
            ->when($this->option('company'), fn ($q, $v) => $q->where('company_id', $v))
            ->when($this->option('order'), fn ($q, $v) => $q->where('order_number', $v))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classify(Order $order): array
    {
        if ($order->status->isTerminal()) {
            return ['NOT_ELIGIBLE', 'terminal status'];
        }

        if ($order->company_id === null) {
            return ['OPERATOR_CORRECTION', 'order has no company'];
        }

        $governorate = trim((string) ($order->governorate ?? ''));

        if ($governorate === '') {
            // Do NOT invent geography. There is no authoritative source on the order
            // from which a governorate could be derived.
            return ['OPERATOR_CORRECTION', 'governorate is NULL — address incomplete'];
        }

        // Would assignment succeed? Ask the engine's own resolver rather than
        // reimplementing coverage rules here.
        if ($this->wouldResolve($order)) {
            return ['RECOVERABLE', 'coverage resolves — assignment + reservation retry'];
        }

        if ($order->status === OrderStatus::AwaitingStock) {
            return ['STATUS_ONLY', 'no coverage; clearing illegal awaiting_stock (ADR-027 §3)'];
        }

        return ['NOT_ELIGIBLE', 'no coverage; status already correct'];
    }

    /**
     * Read-only probe: would assignment resolve for this order?
     *
     * Asks the same CoverageResolutionService and applies the same company/active
     * filter BranchAssignmentEngine::assign() applies, so classification can never
     * drift from what execution will actually do — without writing anything.
     */
    private function wouldResolve(Order $order): bool
    {
        try {
            $candidates = $this->coverage->resolve(
                (string) $order->governorate,
                ($order->area ?? $order->delivery_zone) ?: null,
            );
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return $candidates
            ->filter(fn ($c) => $c->branch !== null
                && $c->branch->company_id === $order->company_id
                && $c->branch->is_active)
            ->isNotEmpty();
    }

    private function apply(Order $order, string $class): string
    {
        if ($class === 'RECOVERABLE') {
            // The engine emits the canonical WarehouseAssigned on success, which the
            // H3 listener turns into the postponed reservation. No status written here.
            $this->branchAssignment->assign($order, $order->company_id);
            $order->refresh();

            return $order->assigned_warehouse_id !== null
                ? 'assigned + reservation retried'
                : 'assignment still unresolved';
        }

        if ($class === 'STATUS_ONLY' || $class === 'OPERATOR_CORRECTION') {
            if ($order->status !== OrderStatus::AwaitingStock) {
                return 'no change needed';
            }

            $before = $order->status->value;

            // Status is NEVER written directly. The Order model guards against it —
            // "All status transitions must go through FulfillmentEngine::run()" — and
            // that guard is correct, so recovery composes two existing workflows:
            //
            //   1. ReturnToPendingWorkflow  awaiting_stock → new, reservation cleared.
            //      This is the sanctioned exit from awaiting_stock for a pre-execution
            //      order, and it releases nothing here because nothing was ever reserved.
            //   2. ProcessOrderWorkflow     re-derives the correct state from scratch.
            //      With no warehouse it now records reservation_status = pending and
            //      leaves the lifecycle status alone (ADR-027 §2) — which is exactly
            //      the state the order should have held all along.
            //
            // The second step is what makes this self-correcting: it does not assume
            // the outcome, it re-runs the real decision.
            $this->fulfillmentEngine->run($this->returnToNewWorkflow, $order, [], null);
            $order->refresh();

            $result = $this->fulfillmentEngine->run($this->processWorkflow, $order, [], null);
            $order = $result->order->refresh();

            OrderEvent::log(
                orderId: $order->id,
                type: 'legacy_reservation_reprocessed',
                description: "Order #{$order->order_number}: recovered from illegal {$before}; re-derived via return_to_in_progress → initiate_order.",
                payload: [
                    'from_status' => $before,
                    'to_status' => $order->status->value,
                    'reservation_status' => $order->reservation_status?->value,
                    'reason' => 'adr027_h4_reprocessing',
                    'blocker' => 'no_warehouse_assigned',
                ],
                module: 'orders',
            );

            return sprintf(
                'status %s → %s; reservation %s',
                $before,
                $order->status->value,
                $order->reservation_status?->value ?? 'null',
            );
        }

        return 'skipped';
    }
}
