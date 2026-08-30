<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Application\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Integration\Domain\Services\AccountRoleResolver;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Integration\Domain\Services\RulePostingStrategy;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Inventory\InventoryItems\Application\Actions\ReceiveStockAction;
use Modules\Inventory\Products\Domain\Enums\InventoryClass;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority;
use Modules\Inventory\InventoryItems\Domain\Services\InboundPostingGuard;
use Modules\Inventory\ReceiptLayers\Application\Actions\CreateReceiptLayersAction;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;
use RuntimeException;
use Throwable;

/**
 * ADR-011 Mode 3: Supplier Invoice → Auto-posting → Inventory.
 *
 * The invoice OWNS the financial document and the landed-cost allocation. It does NOT own
 * inventory: per the P-7 goods-inward ownership ruling it converges on the canonical inbound
 * mechanism — `ReceiveStockAction` for quantity + ledger, `CreateReceiptLayersAction` for
 * FIFO layers and cost propagation — exactly as the Goods Receipt path does.
 *
 * WHAT CHANGED AND WHY. This service used to carry its own inventory engine: it mutated
 * `InventoryItem` directly, created `InventoryReceiptLayer` rows itself with
 * `goods_receipt_id => null`, and wrote to `stock_movements` while the canonical path wrote
 * `stock_ledger_entries`. A delivery with both a Goods Receipt and its linked invoice
 * (`auto_receipt_id`, a real FK) was therefore posted TWICE — double stock, two FIFO layers,
 * and an audit trail split across two tables. Neither path could see the other.
 *
 * Inventory posting is now guarded by the shared ledger reference: an invoice linked to a
 * receipt posts under THAT RECEIPT's reference, so whichever document posts first wins and
 * the second stands down while still completing its own financial work.
 *
 * All steps run inside a single DB transaction; any failure rolls back cleanly.
 */
final class PostSupplierInvoiceService
{
    public function __construct(
        // MaterialCostService is deliberately absent: cost propagation is owned by
        // CreateReceiptLayersAction now, not duplicated here (P-7 ruling).
        private readonly ReceiveStockAction $receiveStock,
        private readonly CreateReceiptLayersAction $createLayers,
        private readonly InboundPostingGuard $inboundGuard,
        private readonly GoodsInwardAuthority $inwardAuthority,
        // V-5 financial leg. `AccountsPayableService` remains the ONE payable authority and the
        // only writer of SupplierLedgerEntry; this service only hands it a document to post.
        private readonly InvoiceReceiptAnchorService $anchors,
        private readonly AccountsPayableService $payables,
        private readonly AccountRoleResolver $roles,
    ) {}

    public function execute(SupplierInvoice $invoice): void
    {
        if (! $invoice->status->canPost()) {
            throw new RuntimeException("Invoice {$invoice->invoice_number} cannot be posted (status: {$invoice->status->value}).");
        }

        DB::transaction(function () use ($invoice): void {
            // ── C-1: acquire the SHARED inbound synchronisation point, FIRST ──────
            //
            // The Goods Receipt path locks the `goods_receipts` row before it mutates
            // anything. This path used to lock only the invoice, so the two documents
            // synchronised on DIFFERENT rows and a receipt and its linked invoice could post
            // the same physical delivery concurrently — two ledger rows, two FIFO layers.
            //
            // Resolving the inbound reference first and locking THAT row makes both paths
            // block on the same mutex. A linked invoice posts under its receipt's reference,
            // so it locks the receipt row — the very row the receipt path holds. An unlinked
            // Mode 3 invoice IS its own inbound, so its own row (locked immediately below)
            // is the synchronisation point. One physical inbound, one lock, either way.
            //
            // No new mechanism: same InnoDB row lock, same reference the certified
            // InboundPostingGuard already defines. Nothing is matched heuristically.
            [$refType, $refId] = $this->inboundGuard->referenceForInvoice(
                $invoice->auto_receipt_id,
                $invoice->id,
            );

            $this->lockCanonicalInbound($refType, $refId);

            // ── PART 3: re-read THIS invoice's posting state under the lock ───────
            //
            // `canPost()` was checked before the transaction opened, so two concurrent
            // posts of the same invoice could both pass it. Re-asserting it here, after the
            // lock, means the loser observes the winner's committed status and stands down
            // through the existing workflow guard rather than posting a second time.
            $locked = SupplierInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->status->canPost()) {
                throw new RuntimeException(
                    "Invoice {$invoice->invoice_number} cannot be posted (status: "
                    .($locked?->status->value ?? 'not found').').',
                );
            }

            $log = [];

            $invoice->update([
                'status' => SupplierInvoiceStatus::AutoProcessing,
                'processing_started_at' => now(),
                'posting_log' => [],
                'posting_error' => null,
            ]);

            try {
                // Step 1 — Load lines eagerly
                $invoice->load(['lines.product', 'supplier', 'warehouse']);
                $log[] = '[1/8] Lines loaded: '.$invoice->lines->count().' item(s)';

                // Step 2 — Allocate landed costs proportionally across lines
                $this->allocateLandedCosts($invoice);
                $log[] = '[2/8] Landed costs allocated (freight + additional)';

                // Steps 3-7 — CANONICAL INBOUND POSTING (P-7 ruling).
                //
                // The invoice does not post inventory itself. It resolves the physical
                // inbound's ledger reference and, if that inbound has not already been
                // posted, delegates to the same canonical actions the Goods Receipt path
                // uses. If it HAS been posted — because the linked Goods Receipt got there
                // first — the invoice completes as a financial document only.
                // $refType / $refId were resolved at the top of this transaction, before the
                // canonical inbound lock was taken — see C-1 above. Deliberately not
                // recomputed here: the value that was locked must be the value that is used.

                // G-1: is the Supplier Invoice this company's goods-inward authority at all?
                //
                // Checked BEFORE the ledger guard because it is the stronger statement. The
                // ledger guard can only catch a delivery that ALREADY posted under a shared
                // reference, and the two documents only share one when `auto_receipt_id` is
                // set — which no production code path ever does, so cross-document protection
                // never fired outside a fixture. Authority removes the need to recognise two
                // documents as the same delivery: unless this company runs Mode 3, an invoice
                // simply never moves stock, whatever order the paperwork is raised in.
                $companyId = (string) ($invoice->company_id ?? $invoice->warehouse?->company_id ?? '');
                $mayPost = $this->inwardAuthority->invoiceMayPost($companyId);

                if (! $mayPost) {
                    $log[] = '[3/8] Goods-inward authority for this company is the Goods Receipt — financial document only';
                    $log[] = '[4/8] Inventory posting skipped (G-1 inbound authority)';
                    $log[] = '[5/8] FIFO layers skipped';
                    $log[] = '[6/8] Stock ledger skipped';
                    $log[] = '[7/8] Cost propagation skipped';
                } elseif ($this->inboundGuard->alreadyPosted($refType, $refId)) {
                    $log[] = "[3/8] Inventory already posted for {$refType} {$refId} — financial posting only";
                    $log[] = '[4/8] Inventory posting skipped (duplicate inbound protection)';
                    $log[] = '[5/8] FIFO layers skipped (owned by the first posting document)';
                    $log[] = '[6/8] Stock ledger skipped';
                    $log[] = '[7/8] Cost propagation skipped — owned by the canonical inbound';
                } else {
                    $preQtys = $this->captureInventorySnapshot($invoice);
                    $log[] = '[3/8] Pre-receipt inventory snapshot captured';

                    $this->postInboundToInventory($invoice, $refType, $refId);
                    $log[] = '[4/8] Inventory quantities updated via ReceiveStockAction';

                    $this->createCanonicalLayers($invoice, $preQtys);
                    $log[] = '[5/8] FIFO receipt layers created via CreateReceiptLayersAction';
                    $log[] = '[6/8] Stock ledger recorded by ReceiveStockAction';
                    $log[] = '[7/8] Product cost intelligence propagated by CreateReceiptLayersAction';
                }

                // ── Step 7b — SUPPLIER PAYABLE (V-5): GRNI clearing + PPV + VAT ───────
                //
                // Runs inside this same transaction and behind the same C-1 lock, so the
                // payable cannot be established without the inventory decision above, and
                // neither can be committed without the other.
                $log[] = $this->postSupplierPayable($invoice, $companyId);

                // Step 8 — Mark posted
                $invoice->update([
                    'status' => SupplierInvoiceStatus::Posted,
                    'posted_by' => Auth::id(),
                    'posted_at' => now(),
                    'posting_log' => $log,
                ]);
                $log[] = '[8/8] Invoice posted successfully';

            } catch (Throwable $e) {
                $invoice->update([
                    'status' => SupplierInvoiceStatus::Failed,
                    'posting_error' => $e->getMessage(),
                    'posting_log' => $log,
                ]);
                throw $e;
            }
        });
    }

    /**
     * Lock the row that represents THIS physical inbound — the shared synchronisation point.
     *
     * `referenceForInvoice()` already defines which document owns the inbound: a linked
     * invoice resolves to its Goods Receipt, an unlinked Mode 3 invoice to itself. Locking
     * whatever that resolves to is what makes the receipt path and the invoice path contend
     * on ONE row instead of two.
     *
     * Deliberately a plain table lock, not an Eloquent model read: this is a mutex, and
     * nothing about the locked row is used to make a decision. Authorisation stays where the
     * certified tenant repair put it — on the invoice lookup itself (PART 7/8) — so this
     * cannot become a tenant bypass. Applying the receipt's tenant scope here would be worse
     * than useless: a foreign row would silently return null, acquiring NO lock and quietly
     * reopening the race it exists to close.
     *
     * The self-referencing case takes no lock here because the invoice row is locked
     * immediately afterwards by the caller — the same row, so a second lock would be noise.
     */
    private function lockCanonicalInbound(string $refType, string $refId): void
    {
        if ($refType !== InboundPostingGuard::REF_GOODS_RECEIPT) {
            return;
        }

        DB::table('goods_receipts')->where('id', $refId)->lockForUpdate()->first();
    }

    /**
     * V-5 — establish the supplier payable from the deterministic receipt anchor.
     *
     * THE ACCOUNTING, for a Mode 1 delivery already received into stock:
     *
     *     Dr GRNI            physical receipt valuation   (clears what the receipt accrued)
     *     Dr/Cr PPV          the difference               (only when there is one)
     *     Dr Input VAT       tax                          (only when a tax code applies)
     *        Cr Trade Payables   approved invoice value + tax
     *
     * Every number comes from an existing authority: the valuation from the anchored receipt
     * line's stamped `landed_unit_cost` (never a FIFO re-read, never today's cost), the accounts
     * from `AccountRoleResolver`, the VAT from the configured tax code, and the posting itself
     * from `AccountsPayableService` — which stays the sole writer of `SupplierLedgerEntry`.
     * Nothing here writes a journal or a ledger row directly.
     *
     * WHEN IT DOES NOT RUN, and why that is deliberate rather than a gap:
     *
     *  - **Any line without an anchor.** GRNI can only be cleared at a valuation, and without an
     *    anchor there is no valuation that is not a guess. The certified inbound suite posts
     *    invoices with unanchored lines, so refusing them outright would change a certified
     *    contract; instead the payable is skipped and the reason recorded on the invoice's own
     *    posting log. Making the anchor mandatory at this boundary is a contract decision.
     *  - **Mode 3.** There the invoice itself moved the stock, so no GRNI was ever accrued and
     *    the debit belongs to the inventory account, not GRNI. That is a different entry and is
     *    not invented here.
     *  - **Already posted.** The idempotency guard below.
     */
    private function postSupplierPayable(SupplierInvoice $invoice, string $companyId): string
    {
        if ($companyId === '') {
            return '[7b] Supplier payable skipped — no company context';
        }

        // Idempotency: one payable per invoice, keyed on the invoice's own identity. Reuses the
        // AP document's natural key rather than introducing a second idempotency mechanism.
        $reference = 'SI-'.$invoice->id;

        if (SupplierBill::query()->where('company_id', $companyId)->where('number', $reference)->exists()) {
            return '[7b] Supplier payable already established for this invoice — no duplicate posted';
        }

        // GRNI only clears what a Goods Receipt actually accrued. Under Mode 3 no receipt ever
        // accrued one, so the invoice debits inventory itself (D-A2) instead of clearing a
        // liability that was never raised.
        if (! $this->inwardAuthority->receiptMayPost($companyId)) {
            return $this->postMode3Payable($invoice, $companyId, $reference);
        }

        // ── D-A1 — Mode 1 requires a deterministic receipt anchor ────────────────
        //
        // GRNI can only be cleared at the valuation the physical receipt committed. Without an
        // anchor that valuation could only be guessed, so a missing or disagreeing anchor is a
        // hard failure, NOT a skip: the exception propagates out of the enclosing
        // DB::transaction and rolls the whole posting back. Nothing survives — no AP document,
        // no GRNI clearing, no PPV, no VAT leg, no SupplierLedgerEntry — and the invoice is
        // never left looking financially posted while having paid nothing.
        //
        // D-A1: deliberately uncaught. An invoice that cannot state the receipt line it settles
        // has no deterministic GRNI to relieve, so it must not post at all — the enclosing
        // transaction rolls the whole document back rather than leaving a half-posted invoice.
        $basis = $this->anchors->basisFor($invoice);

        if ($basis['lines'] === []) {
            return '[7b] Supplier payable skipped — no anchored, positive-quantity lines';
        }

        $grniAccount = $this->roles->resolve($companyId, 'grni');

        // VAT rides the ECONOMIC lines, never a line of its own. AccountsPayableService computes
        // tax as `taxCode->taxFor($net)` on each line's own net, so a dedicated VAT line would
        // have to carry either a zero net — computing zero tax, posting nothing — or the taxable
        // base again, which would double-count the base as an expense. Tagging GRNI and PPV is
        // what makes the tax fall on the invoice value: their nets sum to exactly `invoiceNet`.
        $taxCodeId = $this->resolveTaxCodeId($invoice, $companyId);

        // One GRNI line carrying the physical receipt valuation for every anchored line.
        $lines = [[
            'expense_account_id' => $grniAccount,
            'description' => 'GRNI clearing — '.$invoice->invoice_number,
            'net_amount' => $basis['receiptValuation'],
            'tax_code_id' => $taxCodeId,
        ]];

        // The variance, when there is one. Positive = invoice above receipt (unfavourable, a PPV
        // debit); negative = below (favourable, a PPV credit — the direction the authorised
        // negative-net AP capability makes representable).
        if (abs($basis['variance']) > 0.0001) {
            $lines[] = [
                'expense_account_id' => $this->roles->resolve($companyId, 'purchase_price_variance'),
                'description' => 'Purchase price variance — '.$invoice->invoice_number,
                'net_amount' => $basis['variance'],
                'tax_code_id' => $taxCodeId,
            ];
        }

        $bill = $this->payables->createDocument(
            companyId: $companyId,
            supplierId: (string) $invoice->supplier_id,
            number: $reference,
            documentDate: $invoice->invoice_date,
            lines: $lines,
            description: 'Supplier invoice '.$invoice->invoice_number,
        );

        $this->payables->postDocument($bill, Auth::id());

        return sprintf(
            '[7b] Supplier payable posted — GRNI %s cleared, invoice %s, variance %s',
            $basis['receiptValuation'],
            $basis['invoiceNet'],
            $basis['variance'],
        );
    }

    /**
     * D-A2 — the Mode 3 payable, where the invoice IS the inbound authority.
     *
     *   Dr Inventory           at the line's stamped `landed_unit_cost`, by the product's class
     *   Dr VAT Input           through the configured tax code
     *   Cr Accounts Payable    raised by AccountsPayableService from the debits above
     *
     * There is deliberately NO GRNI leg. GRNI is the liability a Goods Receipt raises for stock
     * it took in before an invoice existed; under Mode 3 no receipt posts, so clearing it would
     * relieve an accrual that was never made — a fabricated entry that happens to balance.
     *
     * There is no PPV leg either, and there cannot be: variance is the invoice price measured
     * against a receipt valuation, and here the invoice is the only valuation in existence.
     */
    private function postMode3Payable(SupplierInvoice $invoice, string $companyId, string $reference): string
    {
        $invoice->loadMissing('lines.product');

        // Grouped by account rather than per line: several raw-material lines share one inventory
        // account, and posting them as a single leg keeps the journal readable while losing no
        // value. Different classes still land on different accounts.
        $byAccount = [];

        foreach ($invoice->lines as $line) {
            $qty = (float) $line->quantity;

            if ($qty <= 0.0) {
                continue;
            }

            // The Mode 3 valuation authority, stamped by allocateLandedCosts() earlier in this
            // same posting run — never a FIFO re-read, never today's cost, never an average.
            $value = $qty * (float) ($line->landed_unit_cost ?? $line->unit_price);

            // Refuses rather than defaults: an unclassifiable product has no inventory account,
            // and guessing one posts real money somewhere that looks fine forever afterwards.
            $class = InventoryClass::fromProductType(
                $line->product?->product_type,
                (string) $line->product_id,
            );

            $account = $this->roles->resolve(
                $companyId,
                RulePostingStrategy::roleForInventoryClass($class->value, 'supplier_invoice.mode3'),
            );

            $byAccount[$account] = ($byAccount[$account] ?? 0.0) + $value;
        }

        if ($byAccount === []) {
            return '[7b] Supplier payable skipped — no positive-quantity lines to value';
        }

        // As in Mode 1, VAT rides the economic lines: tax is computed per line from that line's
        // own net, so tagging the inventory debits puts it on exactly the invoice value. The rate
        // itself is never referenced here — it lives in the configured tax code.
        $taxCodeId = $this->resolveTaxCodeId($invoice, $companyId);

        $lines = [];

        foreach ($byAccount as $accountId => $value) {
            $lines[] = [
                'expense_account_id' => (int) $accountId,
                'description' => 'Inventory — '.$invoice->invoice_number,
                'net_amount' => $value,
                'tax_code_id' => $taxCodeId,
            ];
        }

        $bill = $this->payables->createDocument(
            companyId: $companyId,
            supplierId: (string) $invoice->supplier_id,
            number: $reference,
            documentDate: $invoice->invoice_date,
            lines: $lines,
            description: 'Supplier invoice '.$invoice->invoice_number,
        );

        // Still the sole payable writer — Mode 3 changes which accounts are debited, never who
        // is allowed to raise the credit.
        $this->payables->postDocument($bill, Auth::id());

        return sprintf(
            '[7b] Supplier payable posted (Mode 3) — inventory %s, no GRNI accrual to clear',
            array_sum($byAccount),
        );
    }

    /**
     * The company's active VAT code, when the invoice actually carries tax.
     *
     * Reads the configured tax code; the rate itself is never referenced here.
     */
    private function resolveTaxCodeId(SupplierInvoice $invoice, string $companyId): ?int
    {
        $hasTax = $invoice->lines->contains(fn ($l): bool => (float) ($l->tax_amount ?? 0) > 0);

        if (! $hasTax) {
            return null;
        }

        $id = DB::table('finance_tax_codes')
            ->where('company_id', $companyId)
            ->where('tax_type', 'vat')
            ->where('is_active', true)
            ->whereNotNull('input_account_id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function allocateLandedCosts(SupplierInvoice $invoice): void
    {
        $totalSubtotal = (float) $invoice->subtotal;

        if ($totalSubtotal <= 0) {
            return;
        }

        $freight = (float) $invoice->freight_amount;
        $additional = (float) $invoice->additional_costs;

        foreach ($invoice->lines as $line) {
            /** @var SupplierInvoiceLine $line */
            $ratio = (float) $line->line_total / $totalSubtotal;
            $allocFrt = round($freight * $ratio, 4);
            $allocAdd = round($additional * $ratio, 4);
            $landed = round(((float) $line->unit_price + ($allocFrt + $allocAdd) / max((float) $line->quantity, 1)), 4);

            $line->update([
                'allocated_freight' => $allocFrt,
                'allocated_additional_costs' => $allocAdd,
                'landed_unit_cost' => $landed,
            ]);
        }
    }

    /**
     * Steps 3 + 4 combined.
     *
     * One batch query with pessimistic lock replaces 2N individual InventoryItem
     * lookups (N for the pre-qty snapshot + N for the locked increment).
     *
     * @return array<string, float> product_id → on_hand_qty BEFORE this receipt
     */
    /**
     * on_hand BEFORE this inbound, per product — the base for weighted-average cost.
     *
     * Read-only: the mutation itself belongs to ReceiveStockAction.
     *
     * @return array<string, float>
     */
    private function captureInventorySnapshot(SupplierInvoice $invoice): array
    {
        $productIds = $invoice->lines
            ->filter(fn ($l) => (float) $l->quantity > 0)
            ->pluck('product_id')->unique()->values()->all();

        if ($productIds === []) {
            return [];
        }

        return InventoryItem::query()
            ->whereIn('product_id', $productIds)
            ->where('warehouse_id', $invoice->warehouse_id)
            ->pluck('on_hand_qty', 'product_id')
            ->map(fn ($q): float => (float) $q)
            ->all();
    }

    /**
     * Quantity + stock ledger, through the canonical action.
     *
     * The reference passed here is what makes the pair idempotent: an invoice linked to a
     * receipt posts under the RECEIPT's reference, so the receipt path later finds it.
     */
    private function postInboundToInventory(SupplierInvoice $invoice, string $refType, string $refId): void
    {
        foreach ($invoice->lines as $line) {
            $qty = (float) $line->quantity;

            if ($qty <= 0) {
                continue;
            }

            $this->receiveStock->execute(
                StockOperationDTO::fromArray([
                    'warehouse_id' => $invoice->warehouse_id,
                    'product_id' => $line->product_id,
                    'company_id' => $invoice->warehouse?->company_id,
                    'quantity' => $qty,
                    'reference_type' => $refType,
                    'reference_id' => $refId,
                    'notes' => "Supplier Invoice {$invoice->invoice_number}",
                    'unit_cost' => (float) ($line->landed_unit_cost ?? $line->unit_price),
                ]),
            );
        }
    }

    /**
     * FIFO layers + cost propagation, through the canonical action.
     *
     * `goodsReceiptId` is passed when the invoice describes a linked receipt, so the layer
     * is attributable to the physical document instead of being orphaned — the old path
     * hard-coded `goods_receipt_id => null`.
     *
     * @param  array<string, float>  $preQtys
     */
    private function createCanonicalLayers(SupplierInvoice $invoice, array $preQtys): void
    {
        $lines = $invoice->lines
            ->filter(fn ($l) => (float) $l->quantity > 0)
            ->map(fn ($l) => [
                'product_id' => $l->product_id,
                'quantity' => (float) $l->quantity,
                'landed_unit_cost' => (float) ($l->landed_unit_cost ?? $l->unit_price),
                'goods_receipt_line_id' => null,
            ])
            ->values()
            ->all();

        $this->createLayers->executeForLines(
            lines: $lines,
            warehouseId: $invoice->warehouse_id,
            companyId: $invoice->warehouse?->company_id,
            supplierId: $invoice->supplier_id,
            receiptDate: $invoice->invoice_date->toDateString(),
            preReceiptQtys: $preQtys,
            goodsReceiptId: $invoice->auto_receipt_id,
            costMeta: ['supplier_invoice_id' => $invoice->id],
        );
    }
}
