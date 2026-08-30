<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Services;

use Modules\Admin\Configuration\Domain\Models\BrandPolicy;
use Modules\Admin\Configuration\Domain\Services\ConfigurationManager;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;

/**
 * THE single implementation of the payment→fulfilment contract (ADR-042 §3.1, as amended).
 *
 * Owner decisions implemented here, and nowhere else:
 *
 *   D1-A — MANDATORY FINANCIAL CONTROL. A payment method whose brand `payment_proof_policy`
 *          resolves to `required` blocks fulfilment eligibility until BOTH sufficient payment
 *          AND an active VERIFIED `payment_proofs` record exist.
 *
 *   D2-B — `channel_id IS NULL` does NOT mean "no requirement". It means "no channel-scoped
 *          configuration", and resolution continues down the documented chain.
 *
 * WHY THIS CLASS EXISTS. Before it, the same rule was implemented twice — once in
 * `ConfirmOrderWorkflow::paymentProofRequirement()`/`paymentPermitsConfirmation()` and once
 * inline in `CreateManualOrderAction::resolveManualOrderStatus()` — and the two drifted apart
 * the moment the confirmation gate was hardened: one read `payment_proofs`, the other read the
 * superseded `orders.payment_proof_path` string. Two implementations of one financial control
 * is the defect, not the duplication. There is now exactly one.
 *
 * WHAT THIS CLASS IS NOT. It is not a new payment-policy *source*. Every value it returns comes
 * from a store that already existed and is already authoritative:
 * `config_brand_policies` → company settings → `BrandPolicy::defaultSettings('order')`. It adds
 * no state, no column, no proof source and no status.
 */
final class PaymentFulfillmentGate
{
    /** Non-blocking requirement — the method needs no proof to reach fulfilment. */
    private const REQUIREMENT_REQUIRED = 'required';

    public function __construct(private readonly ConfigurationManager $config) {}

    /**
     * May this EXISTING order hold a fulfilment-eligible status under the payment contract?
     *
     * This is the authority for both directions: `ConfirmOrderWorkflow` asks it before letting
     * an order out of `awaiting_payment`, and `ReevaluateOrderFulfillmentAction` asks it before
     * letting an already-eligible order stay there after its payment method changed.
     */
    public function permits(Order $order): bool
    {
        $method = $this->methodOf($order);

        // No method to evaluate a requirement against — non-blocking, unchanged behaviour.
        //
        // DELIBERATELY STILL PERMISSIVE (owner decision BL-2-A). Failing closed HERE was
        // tried and reverted: `permits()` is consulted by BOTH directions, and the RETURN
        // direction reads `! permits()`, so a blank method demoted every method-less order
        // to `awaiting_payment` on its next payment event. Manual creation legitimately
        // accepts a null method (`StoreManualOrderRequest`), so that punished orders the
        // hardening was never aimed at — two contract tests proved it.
        //
        // The hardening belongs to the ADVANCE decision only, and lives in
        // {@see permitsAdvance()}. The purpose is "blanking a method must not be usable to
        // bypass the control", NOT "every method-less order becomes awaiting_payment".
        if ($method === '') {
            return true;
        }

        if ($this->requirementFor($method, $order->channel_id, $order->company_id) !== self::REQUIREMENT_REQUIRED) {
            return true;
        }

        // Proof-required: BOTH facts must hold (D1-A). Neither substitutes for the other —
        // payment says the amount arrived, the verified proof says it arrived from this
        // customer for this order.
        return $this->isPaidInFull($order) && $this->hasVerifiedProof($order);
    }

    /**
     * May an order being CREATED with this payment method enter a fulfilment-eligible status?
     *
     * A canonical proof is a `payment_proofs` row, and that row can only be written by
     * `POST /orders/{order}/payment-proofs`, which requires an order that already exists.
     * Condition 2 of the contract is therefore **unsatisfiable at creation time**, so a
     * proof-required method is always parked at `awaiting_payment` — regardless of the amount
     * paid, and regardless of any `payment_proof_path` supplied in the request.
     *
     * This is not a stricter rule invented for creation. It is the same rule, evaluated at a
     * moment when one of its two facts cannot yet be true.
     */
    /**
     * The ADVANCE decision — may this order move INTO fulfilment eligibility?
     *
     * ┌─ WHY THIS IS A THIRD ENTRY POINT AND NOT A THIRD GATE ───────────────────┐
     * │ This class already answers two differently-scoped questions with          │
     * │ deliberately different blank-method behaviour — `permits()` (ongoing) and  │
     * │ `permitsAtCreation()` (creation). This is the third: "may it advance?".    │
     * │                                                                          │
     * │ It is NOT a second gate and NOT a second source of truth. The four-term    │
     * │ conjunction is not restated — this delegates to `permits()` for every      │
     * │ term. The ONLY thing it adds is the blank-method answer, which differs by  │
     * │ direction and by nothing else.                                            │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * FAILS CLOSED ON A MISSING METHOD (owner decision Q3/O3, scoped by BL-2-A). A control
     * that cannot identify which policy applies has not been satisfied — it has failed to
     * evaluate — so it must not be the thing that lets an order into fulfilment. This closes
     * the reachable bypass: blanking `payment_method_manual` used to make the outermost term
     * of a financial control satisfiable by REMOVING information.
     *
     * Deliberately asymmetric with `permits()`, and the asymmetry is the whole point:
     *
     *   ADVANCE (this)    blank method -> REFUSED. Blanking must not buy passage.
     *   RETURN (permits)  blank method -> permitted. A method-less order is not demoted
     *                     merely for lacking a method; that punishes orders the hardening
     *                     was never aimed at, and manual creation legitimately allows null.
     *   CREATION          blank method -> permitted, unchanged (CreateOrderAction always
     *                     passes null; failing closed there would break creation).
     *
     * Paired with O1, which rejects the blanking at the HTTP edge. Both are kept: the
     * request rule stops the common path, and this stops every other writer — a seeder, an
     * importer, a console command, or a future endpoint that never sees that rule.
     *
     * Impact measured before the change (read-only, 2026-08-23): 19/19 live orders carry an
     * effective method (cod 14, instapay 4, mobile_wallet 1); ZERO blank. No existing order
     * changes behaviour and no data remediation was required.
     */
    public function permitsAdvance(Order $order): bool
    {
        if ($this->methodOf($order) === '') {
            return false;
        }

        return $this->permits($order);
    }

    public function permitsAtCreation(?string $method, ?string $channelId, ?string $companyId): bool
    {
        $method = trim((string) $method);

        if ($method === '') {
            return true;
        }

        return $this->requirementFor($method, $channelId, $companyId) !== self::REQUIREMENT_REQUIRED;
    }

    /** The order's effective payment method — manual entry wins over the channel-supplied one. */
    public function methodOf(Order $order): string
    {
        return trim((string) ($order->payment_method_manual ?? $order->payment_method ?? ''));
    }

    /**
     * Resolve the proof requirement ('none' | 'optional' | 'required') for a payment method,
     * through the documented scope chain — ADR-042 §3.1 and ENTERPRISE-CONFIGURATION-PLATFORM
     * §8 (GOV-003, GOV-006, GOV-007).
     *
     *   1. Channel scope  → channels.brand_id → config_brand_policies
     *   2. Company scope  → config_company_settings, group 'order'
     *   3. System default → BrandPolicy::defaultSettings('order')
     *
     * D2-B: there is deliberately NO `channel_id === null → 'none'` short-circuit. That
     * hardcode was not a default — it bypassed a default that already answers `required` for
     * instapay/bank_transfer/mobile_wallet, since `ConfigurationManager::getBrandPolicy()`
     * falls back to `BrandPolicy::defaultSettings()` for any brand with no policy row. The only
     * way to reach `'none'` for instapay was to have no brand at all, which an ordinary order
     * edit can cause by nulling `channel_id`. A control an order editor can switch off is not
     * a control.
     *
     * A method the resolved policy does not mention still yields `'none'` — that is the
     * pre-existing key-miss behaviour and is deliberately unchanged (it is what keeps
     * WooCommerce gateway ids such as `bacs` inert).
     */
    public function requirementFor(string $method, ?string $channelId, ?string $companyId): string
    {
        $policy = $this->orderPolicyFor($channelId, $companyId);

        return (string) ($policy[$method] ?? 'none');
    }

    /**
     * The `payment_proof_policy` map that governs an order, resolved down the chain above.
     *
     * @return array<string, string>
     */
    public function proofPolicyFor(?string $channelId, ?string $companyId): array
    {
        return $this->orderPolicyFor($channelId, $companyId);
    }

    /** @return array<string, string> */
    private function orderPolicyFor(?string $channelId, ?string $companyId): array
    {
        // 1. Channel scope — the brand behind the channel. Unchanged behaviour when a channel
        //    resolves; `getBrandPolicy()` already falls back to the system default internally.
        if ($channelId !== null && $channelId !== '') {
            $brandId = Channel::query()->whereKey($channelId)->value('brand_id');

            if ($brandId !== null) {
                $policy = $this->config->getBrandPolicy((string) $brandId, 'order');
                $map = $policy['payment_proof_policy'] ?? null;

                if (is_array($map) && $map !== []) {
                    return self::stringMap($map);
                }
            }
        }

        // 2. Company scope — the documented next step in the chain. Present in the platform
        //    today and read here; it stays inert until a company actually configures one,
        //    which is the correct behaviour for an unconfigured scope, not a gap.
        if ($companyId !== null && $companyId !== '') {
            $settings = $this->config->getCompanySettings((string) $companyId, 'order');
            $map = $settings['payment_proof_policy'] ?? null;

            if (is_array($map) && $map !== []) {
                return self::stringMap($map);
            }
        }

        // 3. System default — already the authority for every brand without a policy row.
        $defaults = BrandPolicy::defaultSettings('order');
        $map = $defaults['payment_proof_policy'] ?? [];

        return is_array($map) ? self::stringMap($map) : [];
    }

    /**
     * Paid in full — the same derivation the read model uses (`EloquentOrderRepository`).
     * No payment state is stored anywhere; money is the truth.
     */
    public function isPaidInFull(Order $order): bool
    {
        return (float) $order->deposit_amount >= (float) $order->total;
    }

    /**
     * Does this order carry an ACTIVE, VERIFIED payment proof?
     *
     * `payment_proofs` is the ONLY source. The legacy `orders.payment_proof_path` column is
     * deliberately NOT consulted: it is unvalidated free text (`nullable|string|max:500`) with
     * no storage, tenant, existence or MIME check, so any non-empty value would clear a
     * REQUIRED-proof gate with zero money and zero evidence.
     *
     * ACTIVE means `superseded_at IS NULL` — a replaced proof stops clearing the gate even if
     * it was already verified. VERIFIED (never merely `uploaded`) is required: evidence
     * submitted is not evidence accepted. `rejected` never clears the gate.
     *
     * TENANCY (defence in depth). The proof must belong to the SAME company as the order it
     * clears. `PaymentProof` carries no tenant global scope, and the only tenant check on the
     * lifecycle today is a manual `where('company_id', …)` in `PaymentProofController`; a caller
     * reaching an action by any other route is unscoped. Since this method is the point at which
     * evidence becomes a financial decision, it does not rely on an upstream guard it does not
     * own. The predicate is behaviour-neutral for correctly written rows — `UploadPaymentProofAction`
     * always stamps `company_id` from the order — and closes the gate on a mismatched one.
     *
     * An order with a NULL `company_id` therefore satisfies no proof-required gate at all:
     * `payment_proofs.company_id` is NOT NULL, so the comparison can never match. That is the
     * correct fail-closed answer for a row with no tenant, not an edge case to special-case.
     */
    public function hasVerifiedProof(Order $order): bool
    {
        return PaymentProof::query()
            ->where('order_id', $order->id)
            ->where('company_id', $order->company_id)
            ->whereNull('superseded_at')
            ->where('state', PaymentProofState::Verified->value)
            ->exists();
    }

    /**
     * @param  array<array-key, mixed>  $map
     * @return array<string, string>
     */
    private static function stringMap(array $map): array
    {
        $out = [];

        foreach ($map as $key => $value) {
            if (is_string($value)) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
