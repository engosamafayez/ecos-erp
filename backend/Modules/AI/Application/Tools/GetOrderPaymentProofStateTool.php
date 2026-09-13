<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;

/**
 * Read-only proof-inspection tool, gated on the same narrow `proof_view` grant
 * this codebase already uses for exactly this purpose (TASK-ECOS-ORDERS-
 * PAYMENT-PROOF-RBAC-TEMPLATE-ALIGNMENT-001) — inspecting a proof and
 * exercising the verify/reject control are deliberately different rights, and
 * this tool only ever reads.
 */
final class GetOrderPaymentProofStateTool implements AIToolInterface
{
    public function name(): string
    {
        return 'get_order_payment_proof_state';
    }

    public function description(): string
    {
        return 'Reports whether a payment proof has been uploaded for an order, and whether it has been verified, rejected, or is still pending review.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function permission(): string
    {
        return 'sales.orders.proof_view';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::Read;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $orderId = is_string($input['order_id'] ?? null) ? $input['order_id'] : null;

        if ($orderId === null || $orderId === '') {
            return AIToolResult::invalidInput('order_id is required.');
        }

        $proof = PaymentProof::query()
            ->where('company_id', $context->companyId)
            ->where('order_id', $orderId)
            ->latest('uploaded_at')
            ->first();

        if ($proof === null) {
            return AIToolResult::success(['has_proof' => false, 'state' => null]);
        }

        return AIToolResult::success([
            'has_proof' => true,
            'state' => $proof->state?->value,
            'uploaded_at' => $proof->uploaded_at?->toIso8601String(),
            'verified_at' => $proof->verified_at?->toIso8601String(),
            'rejected_at' => $proof->rejected_at?->toIso8601String(),
            'rejection_reason' => $proof->rejection_reason,
        ]);
    }
}
