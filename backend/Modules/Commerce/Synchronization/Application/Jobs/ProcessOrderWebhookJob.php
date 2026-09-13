<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\OrderImport\Application\Services\WooCommerceOrderImporter;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Application\Services\WooCommerceOrderStatusTranslator;
use Modules\Commerce\Synchronization\Application\Services\WooRefundApplicationService;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CancelOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteDeliveryWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\SetEarlyStatusWorkflow;
use Throwable;

/**
 * Processes an inbound WooCommerce order webhook (created/updated/deleted).
 *
 * P5 fix: replaced the inline status map and parallel inventory pipeline with
 * the canonical WooCommerceOrderStatusTranslator + FulfillmentEngine routing.
 * - ONE status translation map (WooCommerceOrderStatusTranslator)
 * - ONE inventory pipeline (via FulfillmentEngine workflows)
 * - withoutEvents() removed — domain events now fire normally
 *
 * Guard failures are non-fatal: if ECOS and WC are out of sync, the webhook
 * logs a warning and skips the transition rather than crashing.
 *
 * TASK-ECOS-V1.1-WOOCOMMERCE-WOO-01-REFUND-FINANCE-INTEGRATION-043 (revised
 * under TASK-ECOS-V1.1-WOO-01-VERIFICATION-REMEDIATION-CHECKPOINT-043-R1) —
 * refunds are no longer part of the generic status-transition match() below.
 * Woo's `refunds` array is read directly off this SAME payload (WooCommerce
 * already includes it on the order representation — no new webhook topic is
 * registered) and delegated, per entry, to WooRefundApplicationService, which
 * is FINANCIAL-ONLY: it posts a Credit Note against the order's existing
 * invoice (see its own class docblock) and never touches Order.status,
 * Inventory, or ReturnOrderWorkflow — a refunded line-item quantity is
 * commercial/accounting allocation, not physical-return evidence, so
 * 'refunded' reaching this job produces exactly one effect (the financial
 * one) and never a second, generic-status-transition effect: it is
 * deliberately excluded from the match() below so the two can never fire
 * for the same event. Physical return remains entirely the canonical ECOS
 * warehouse/driver flow, untouched by this job.
 *
 * A partial Woo refund does not change the order's own `status` (WooCommerce
 * only sets status=refunded when the entire order total has been refunded),
 * so refund processing runs unconditionally — independent of whether a status
 * transition is also detected below.
 */
final class ProcessOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly Channel $channel,
        private readonly array $payload,
        private readonly string $webhookAction,
    ) {}

    public function handle(
        SyncLogService $logService,
        WooCommerceOrderImporter $importer,
        WooCommerceOrderStatusTranslator $translator,
        FulfillmentEngine $fulfillmentEngine,
        ProcessOrderWorkflow $processWorkflow,
        CancelOrderWorkflow $cancelWorkflow,
        CompleteDeliveryWorkflow $deliverWorkflow,
        SetEarlyStatusWorkflow $earlyStatusWorkflow,
        WooRefundApplicationService $refundService,
    ): void {
        $externalId = (string) ($this->payload['id'] ?? '');

        // TASK-...-WOO-04 (042A-R1 §5) — "inbound webhook processing remain[s] inert" until
        // TransitionChannelToLiveAction runs, regardless of is_active/sync_* flags. Recorded as
        // a skip (the existing convention for duplicate-webhook detection), not a failure.
        // TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §17 — canSyncNow() also holds a stale
        // native Woo webhook for a paired Channel whose Connector has explicitly disconnected
        // or gone stale, rather than processing it as though the integration were healthy.
        if (! $this->channel->canSyncNow()) {
            $logService->createSkippedLog(
                $this->channel,
                SyncEntityType::Order,
                SyncDirection::Inbound,
                $this->webhookAction,
                $externalId,
                $this->payload,
            );

            return;
        }

        $log = $logService->createLog(
            $this->channel,
            SyncEntityType::Order,
            SyncDirection::Inbound,
            $this->webhookAction,
            $externalId,
            SyncStatus::Processing,
            $this->payload,
        );

        try {
            $existingOrder = $externalId !== ''
                ? Order::query()
                    ->where('external_order_id', $externalId)
                    ->where('channel_id', $this->channel->id)
                    ->first()
                : null;

            if ($existingOrder !== null) {
                $wooStatus = (string) ($this->payload['status'] ?? '');
                $ecosStatus = $translator->translate($wooStatus);

                // Refunds run unconditionally — a partial refund never changes Woo's own
                // order status, so this cannot be folded into the status-diff branch below.
                // Money (Credit Note) and goods (ReturnOrderWorkflow) are decided
                // independently inside the service; see its class docblock.
                $refundResults = $this->processRefunds($refundService, $existingOrder);

                // 'refunded' is handled ENTIRELY by processRefunds() above — ReturnOrderWorkflow
                // is invoked only there, only when Woo's own refund detail evidences returned
                // goods, and only with the context keys its guard actually reads. It must not
                // also be reached via the generic status-transition match() below.
                if ($wooStatus !== 'refunded' && $ecosStatus !== null && $ecosStatus !== $existingOrder->status) {
                    // Route through the appropriate canonical workflow.
                    // Guard failures are skipped — WC/ECOS state divergence is expected
                    // when orders are managed in ECOS outside the WC lifecycle.
                    $workflow = match (true) {
                        in_array($wooStatus, ['cancelled', 'failed'], true) => $cancelWorkflow,
                        $wooStatus === 'processing' => $processWorkflow,
                        $wooStatus === 'completed' => $deliverWorkflow,
                        default => $earlyStatusWorkflow,
                    };

                    try {
                        $fulfillmentEngine->run(
                            $workflow,
                            $existingOrder,
                            ['target_status' => $ecosStatus->value, 'reason' => "WooCommerce webhook: {$wooStatus}"],
                            null, // system actor
                        );
                    } catch (Throwable $workflowError) {
                        // TASK-...-WOO-06 (042A-R1 §2/§10) — non-fatal: a guard rejection means
                        // WC and ECOS disagree about state. This is EXPECTED, not an error to
                        // fix — most visibly for Woo "completed": CompleteDeliveryWorkflow's own
                        // guard (OutForDelivery + inventory_shipped_at already set) is the ONLY
                        // authority for reaching Delivered, and it is deliberately unchanged
                        // here. A "completed" webhook arriving before ECOS's own dispatch made
                        // that guard satisfiable is HELD, never forced — Woo status remains
                        // informational, never physical evidence.
                        //
                        // Previously this was only a transient log line, invisible to an
                        // operator. Recorded now as a distinct, queryable SyncLog entry — the
                        // same existing convention duplicate-webhook/signature-rejected skips
                        // already use, not a new audit mechanism.
                        $logService->createSkippedLog(
                            $this->channel,
                            SyncEntityType::Order,
                            SyncDirection::Inbound,
                            'fulfillment_transition_held',
                            $existingOrder->id,
                            [
                                'wc_status' => $wooStatus,
                                'ecos_from' => $existingOrder->status->value,
                                'ecos_to' => $ecosStatus->value,
                                'reason' => $workflowError->getMessage(),
                            ],
                        );
                    }
                }

                $logService->markSuccess($log, [
                    'message' => 'Order status processed.',
                    'order_id' => $existingOrder->id,
                    'refunds_processed' => $refundResults,
                ], $this->channel);
            } else {
                $created = $importer->importSingle($this->channel, $this->payload);
                $logService->markSuccess(
                    $log,
                    ['message' => $created ? 'Order created.' : 'Order skipped (no valid lines).'],
                    $created ? $this->channel : null,
                );
            }
        } catch (Throwable $e) {
            $logService->markFailed($log, $e->getMessage(), null, $this->channel);
            throw $e;
        }
    }

    /**
     * Apply every not-yet-seen entry in Woo's own `refunds` summary array
     * against this order. Each entry is independently idempotent inside
     * WooRefundApplicationService (durable, DB-enforced — see its docblock),
     * so re-processing the same payload on a retried webhook delivery is safe.
     *
     * @return list<array<string, mixed>>
     */
    private function processRefunds(WooRefundApplicationService $refundService, Order $order): array
    {
        /** @var list<array<string, mixed>> $refunds */
        $refunds = is_array($this->payload['refunds'] ?? null) ? $this->payload['refunds'] : [];

        // WooCommerce refunds carry no currency of their own — it belongs to the parent
        // order. Attached here so the service can refuse a currency mismatch explicitly
        // rather than silently posting in whatever currency the invoice happens to use.
        $orderCurrency = isset($this->payload['currency']) ? (string) $this->payload['currency'] : null;

        $results = [];

        foreach ($refunds as $wooRefundSummary) {
            if (! is_array($wooRefundSummary) || ! isset($wooRefundSummary['id'], $wooRefundSummary['total'])) {
                continue;
            }

            if ($orderCurrency !== null && ! isset($wooRefundSummary['currency'])) {
                $wooRefundSummary['currency'] = $orderCurrency;
            }

            $outcome = $refundService->applyRefund($this->channel, $order, $wooRefundSummary);

            $results[] = [
                'woo_refund_id' => $wooRefundSummary['id'],
                ...$outcome->toLogPayload(),
            ];
        }

        return $results;
    }
}
