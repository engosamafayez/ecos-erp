<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Admin\GoLive\Domain\Enums\ResetDomain;
use Modules\Admin\GoLive\Domain\Enums\ResetOperationStatus;
use Modules\Admin\GoLive\Domain\Models\GoLiveResetOperation;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Organization\Companies\Domain\Services\CompanyLifecycleAuthority;
use RuntimeException;
use Throwable;

/**
 * TASK-...-026 §7/§8/§15/§17 — the ONE destructive execution path. Every safety gate below is
 * re-checked HERE, at execution time, never trusted from a Preview call the client already saw
 * (§6: "do not bypass constraints to make the action succeed" implies the same for staleness —
 * the world can change between Preview and Execute).
 *
 * Order of checks: Live-lock (§17, absolute — checked first, unconditionally) → idempotency
 * (§7/§21.22, returns the existing operation rather than re-running) → concurrent-run guard →
 * confirmation phrase (§15, server-computed and server-validated, never client-supplied) →
 * unsafe-combination re-check (§6). Only once ALL of these pass does a `golive_reset_operations`
 * row get created and the transaction begin.
 */
final class ExecuteGoLiveResetAction
{
    public function __construct(
        private readonly CompanyLifecycleAuthority $lifecycle,
        private readonly PreviewGoLiveResetAction $preview,
        private readonly \Modules\Admin\GoLive\Application\Services\CommerceResetService $commerce,
        private readonly \Modules\Admin\GoLive\Application\Services\OperationsResetService $operations,
        private readonly \Modules\Admin\GoLive\Application\Services\InventoryResetService $inventory,
        private readonly \Modules\Admin\GoLive\Application\Services\FinanceResetService $finance,
    ) {}

    /**
     * @param  list<string>  $selectedDomains
     */
    public function execute(
        string $companyId,
        array $selectedDomains,
        string $confirmationPhrase,
        string $idempotencyKey,
        ?string $reason = null,
    ): GoLiveResetOperation {
        // §17 — absolute. No approved reversal contract exists, so this is not "usually" blocked
        // for Live companies, it is unconditionally blocked, checked before anything else runs.
        if ($this->lifecycle->isLive($companyId)) {
            throw new RuntimeException('This company is Live. Destructive test-data reset is permanently unavailable.');
        }

        // §7/§21.22 — duplicate-execution protection. A retried request with the SAME
        // idempotency_key returns the original outcome rather than resetting twice.
        $existing = GoLiveResetOperation::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Concurrency guard — one in-flight reset per company at a time.
        $inFlight = GoLiveResetOperation::query()
            ->where('company_id', $companyId)
            ->where('status', ResetOperationStatus::Executing->value)
            ->exists();
        if ($inFlight) {
            throw new RuntimeException('A reset operation is already in progress for this company.');
        }

        // §15 — high-friction confirmation, server-computed and server-validated. The phrase is
        // deterministic from the company's own code, never accepted as a client-declared value to
        // compare against itself.
        $company = Company::query()->findOrFail($companyId);
        $expectedPhrase = 'RESET '.$company->code;
        if (! hash_equals($expectedPhrase, $confirmationPhrase)) {
            throw new RuntimeException("Confirmation phrase does not match. Expected exactly: \"{$expectedPhrase}\".");
        }

        // §6 — re-checked now, not trusted from an earlier Preview call.
        $previewNow = $this->preview->execute($companyId, $selectedDomains);
        if (! $previewNow->isSafe()) {
            throw new RuntimeException('Unsafe combination: '.implode(' ', $previewNow->blockers));
        }

        $operation = GoLiveResetOperation::query()->create([
            'company_id' => $companyId,
            'actor_id' => Auth::id(),
            'idempotency_key' => $idempotencyKey,
            'status' => ResetOperationStatus::Executing->value,
            'selected_domains' => $previewNow->selectedDomains,
            'preserved_domains' => array_values(array_diff(
                array_map(static fn (ResetDomain $d) => $d->value, ResetDomain::cases()),
                $previewNow->selectedDomains,
            )),
            'preview_counts' => $previewNow->counts,
            'reason' => $reason,
            'lifecycle_state_at_run' => $this->lifecycle->stateFor($companyId)->value,
        ]);

        $stage = 'start';

        try {
            $executionCounts = DB::transaction(function () use ($companyId, $previewNow, &$stage): array {
                $counts = [];

                foreach ($previewNow->selectedDomains as $domainValue) {
                    $domain = ResetDomain::from($domainValue);
                    $stage = $domain->value;
                    $counts[$domain->value] = match ($domain) {
                        ResetDomain::Commerce => $this->commerce->execute($companyId),
                        ResetDomain::Operations => $this->operations->execute($companyId),
                        ResetDomain::Inventory => $this->inventory->execute($companyId),
                        ResetDomain::Finance => $this->finance->execute($companyId),
                    };
                }

                return $counts;
            });
        } catch (Throwable $e) {
            // TASK-...-026 §8 — the transaction has already rolled back by the time we get here;
            // this update is a SEPARATE statement recording the failure, not part of the rolled-
            // back work. "Never leave a reset reported as successful after partial failure."
            $operation->update([
                'status' => ResetOperationStatus::Failed->value,
                'failure_stage' => $stage,
                'failure_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }

        $operation->update([
            'status' => ResetOperationStatus::Completed->value,
            'execution_counts' => $executionCounts,
            'completed_at' => now(),
        ]);

        return $operation->refresh();
    }
}
