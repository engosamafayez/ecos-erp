<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§4/§5/§9-§12).
 *
 * ONE backend action behind BOTH "Block Customer" and "Block Phone" (§11/§12) —
 * they are the same operation with a different starting identity, and a plain
 * `?customerId` XOR `?rawPhone` input avoids a second, drifting implementation
 * of block creation, race handling and the existing-Orders sweep.
 *
 * CONCURRENCY (§9/§39-A). The active-block uniqueness invariant is a real DB
 * constraint (customer_blocks.active_phone_key — see the creating migration),
 * not an application exists() check. The pre-check below is only a fast common
 * path; the actual safety is: attempt the INSERT, and on a duplicate-key error
 * (MySQL 1062 / SQLSTATE 23000) re-read and return the row that actually won the
 * race instead of surfacing an error to a legitimate second "block this phone"
 * request. MySQL/InnoDB — unlike PostgreSQL — does not poison the enclosing
 * transaction on a caught duplicate-key error, so continuing to use the SAME
 * transaction (to then apply the block to existing Orders) is safe here; this is
 * documented explicitly because it is a genuine engine-specific behaviour this
 * task's own concurrency evidence must account for (§50).
 */
final class BlockCustomerOrPhoneAction extends BaseAction
{
    public function __construct(
        private readonly BlockedCustomerPolicy $policy,
        private readonly ApplyBlockToExistingOrdersAction $applyToExistingOrders,
    ) {}

    /**
     * @param  mixed  ...$arguments  [0] companyId, [1] ?customerId, [2] ?rawPhone, [3] reason, [4] ?actorId
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $companyId = (string) $arguments[0];
        $customerId = $arguments[1] ?? null;
        $rawPhone = $arguments[2] ?? null;
        $reason = trim((string) $arguments[3]);
        $actorId = $arguments[4] ?? null;

        $customer = $customerId !== null
            ? Customer::query()->where('id', $customerId)->where('company_id', $companyId)->firstOrFail()
            : null;

        // §4 — phone-first identity. An existing Customer's OWN saved phone/mobile
        // is the identity that gets blocked, never the Customer id alone.
        $normalizedPhone = $customer !== null
            ? $this->policy->normalize($customer->phone ?? $customer->mobile)
            : $this->policy->normalize($rawPhone);

        if ($normalizedPhone === '') {
            abort(422, 'A phone number is required to block — the Customer has no phone/mobile on file, or none was supplied.');
        }

        // §12 — Block Phone may target a phone that already belongs to a Customer;
        // bind it (§10) rather than leaving a Customer record with an unlinked block.
        //
        // customers.phone/mobile is raw, unnormalized input (confirmed: no
        // normalization exists anywhere in Sales\Customers) — it may hold
        // "01099998888", "+201099998888" or any other equivalent form, so a plain
        // `WHERE phone = $normalizedPhone` would miss a stored value that is not
        // ALREADY in normalized form. The exact raw string is checked first (cheap,
        // matches the common case where the same format is typed each time); if
        // that misses, every phone-bearing Customer in the company is normalized
        // in PHP and compared — a Block Phone request is a rare, deliberate admin
        // action, not a hot path, so this is an acceptable cost for correctness.
        if ($customer === null) {
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $q) use ($normalizedPhone, $rawPhone): void {
                    $q->where('phone', $normalizedPhone)->orWhere('mobile', $normalizedPhone);
                    if ($rawPhone !== null && $rawPhone !== '') {
                        $q->orWhere('phone', $rawPhone)->orWhere('mobile', $rawPhone);
                    }
                })
                ->first();
        }

        if ($customer === null) {
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $q): void {
                    $q->whereNotNull('phone')->orWhereNotNull('mobile');
                })
                ->get()
                ->first(fn (Customer $c) => $this->policy->normalize($c->phone) === $normalizedPhone
                    || $this->policy->normalize($c->mobile) === $normalizedPhone);
        }

        [$block, $created] = DB::transaction(function () use ($companyId, $normalizedPhone, $customer, $reason, $actorId): array {
            $existingActive = $this->policy->activeBlockForPhone($companyId, $normalizedPhone);

            if ($existingActive !== null) {
                if ($customer !== null && $existingActive->customer_id === null) {
                    $existingActive->update(['customer_id' => $customer->id]);
                }

                return [$existingActive, false];
            }

            try {
                $block = CustomerBlock::create([
                    'company_id' => $companyId,
                    'customer_id' => $customer?->id,
                    'normalized_phone' => $normalizedPhone,
                    'is_active' => true,
                    'block_reason' => $reason,
                    'blocked_by' => $actorId,
                    'blocked_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateActiveBlock($e)) {
                    throw $e;
                }

                // Lost the race to a concurrent "block this phone" request (§9/§39-A).
                // The winner's row is the one active authority — reuse it.
                $winner = $this->policy->activeBlockForPhone($companyId, $normalizedPhone);

                if ($winner === null) {
                    throw $e;
                }

                return [$winner, false];
            }

            $this->applyToExistingOrders->execute($block);

            return [$block, true];
        });

        return OperationResult::success(
            $block,
            $created ? 'Customer/phone blocked.' : 'This phone is already blocked.',
        );
    }

    private function isDuplicateActiveBlock(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
