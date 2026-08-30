<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptLineDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseMaterialReceivingException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseOrderCancelledException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseOrderClosedException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Exceptions\InvalidPurchaseOrderStatusException;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;

final class CreateGoodsReceiptAction extends BaseAction
{
    public function __construct(private readonly GoodsReceiptRepositoryInterface $receipts) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof GoodsReceiptDTO) {
            throw new InvalidArgumentException('CreateGoodsReceiptAction::execute expects a GoodsReceiptDTO.');
        }

        // ── Which anchor is this receipt raised against? ──────────────────────
        // Part 1: a receipt is EITHER a legacy purchase-order receipt OR a Purchase
        // (purchase-material) receipt — never both. The two branches share everything
        // downstream: the same repository, the same posting action, the same certified
        // inventory path. Only the source of ordered quantity, price and supplier differs.
        $isPurchaseMaterialAnchored = $this->isPurchaseMaterialAnchored($dto);

        $po = null;

        if (! $isPurchaseMaterialAnchored) {
            $po = PurchaseOrder::query()->find($dto->purchase_order_id);

            if (! $po instanceof PurchaseOrder) {
                throw new InvalidPurchaseOrderStatusException((string) $dto->purchase_order_id, 'not_found');
            }

            if ($po->status === PurchaseOrderStatus::Cancelled) {
                throw new PurchaseOrderCancelledException($po->po_number);
            }

            if ($po->status === PurchaseOrderStatus::Closed) {
                throw new PurchaseOrderClosedException($po->po_number);
            }

            if (! $po->status->canReceive()) {
                throw new InvalidPurchaseOrderStatusException(
                    $po->po_number,
                    $po->status->value,
                    [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value],
                );
            }
        }

        // Purchase-material lines, keyed by id — resolved once and reused for supplier
        // identity, unit price and the ordered quantity.
        $pmLines = $isPurchaseMaterialAnchored ? $this->resolvePurchaseMaterialLines($dto) : collect();

        if ($isPurchaseMaterialAnchored) {
            // RD-1 — supplier identity comes from the purchase material line, and one
            // receipt is one supplier. Validated here so a receipt can never be created
            // with an unattributable or mixed supplier.
            $this->assertSingleSupplier($pmLines);
        }

        $attributes = [
            'receipt_number' => $this->receipts->nextReceiptNumber(),
            'purchase_order_id' => $dto->purchase_order_id,
            'warehouse_id' => $dto->warehouse_id,
            // B-2. `company_id` was added to this table nullable, backfilled once, and then
            // never written again — so every receipt created since carried NULL, the list
            // filter that reads it matched nothing, and the column could not be used to scope
            // access. Ownership is resolved from the purchase order, exactly as the column's
            // own backfill migration defines it, falling back to the receiving warehouse.
            // Purchase-anchored receipts have no PO, so ownership comes from the Purchase
            // itself, falling back to the receiving warehouse exactly as the PO branch does.
            'company_id' => $isPurchaseMaterialAnchored
                ? ($this->purchaseMaterialCompanyId($pmLines) ?? $this->warehouseCompanyId($dto->warehouse_id))
                : ($po?->company_id ?? $this->warehouseCompanyId($dto->warehouse_id)),
            'receipt_date' => $dto->receipt_date,
            'status' => GoodsReceiptStatus::Draft->value,
            'notes' => $dto->notes,
            // Supplier invoice
            'supplier_invoice_number' => $dto->supplier_invoice_number,
            'supplier_invoice_date' => $dto->supplier_invoice_date,
            'invoice_attachment_path' => $dto->invoice_attachment_path,
            // Invoice financials
            'invoice_total_amount' => $dto->invoice_total_amount,
            'paid_amount' => $dto->paid_amount,
            'freight_amount' => $dto->freight_amount,
            'tax_amount' => $dto->tax_amount,
            'additional_costs' => $dto->additional_costs,
            // Payment tracking — auto-derived from paid_amount unless explicitly overridden
            'payment_status' => $dto->payment_status
                ?? GoodsReceipt::derivePaymentStatus($dto->paid_amount, $dto->invoice_total_amount),
            'payment_method' => $dto->payment_method,
            'payment_terms_days' => $dto->payment_terms_days,
            'payment_due_date' => $this->resolvePaymentDueDate($dto),
        ];

        $poLineUnitPrices = $isPurchaseMaterialAnchored
            ? collect()
            : PurchaseOrderLine::query()
                ->whereIn('id', array_filter(array_map(fn (GoodsReceiptLineDTO $l): ?string => $l->purchase_order_line_id, $dto->lines)))
                ->pluck('unit_price', 'id');

        $productIds = array_map(fn (GoodsReceiptLineDTO $l): string => $l->product_id, $dto->lines);
        $products = Product::query()->with('unit')->whereIn('id', $productIds)->get()->keyBy('id');

        $lines = array_map(function (GoodsReceiptLineDTO $line) use ($poLineUnitPrices, $products, $pmLines): array {
            // Price precedence mirrors the PO branch: the ordering document's agreed price
            // wins over anything the client sent, falling back to the submitted price.
            $pmLine = $line->purchase_material_line_id !== null
                ? $pmLines->get($line->purchase_material_line_id)
                : null;

            $unitPrice = $pmLine !== null
                ? (float) ($pmLine->agreed_price ?? $line->unit_price)
                : (float) ($poLineUnitPrices[$line->purchase_order_line_id] ?? $line->unit_price);

            $variance = $line->net_received_quantity - $line->ordered_quantity;
            $product = $products->get($line->product_id);
            $unit = $product?->unit;

            return [
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'purchase_material_line_id' => $line->purchase_material_line_id,
                'product_id' => $line->product_id,
                'uom_id_snapshot' => $unit?->id,
                'uom_name_snapshot' => $unit?->name,
                'uom_symbol_snapshot' => $unit?->symbol,
                'ordered_quantity' => $line->ordered_quantity,
                'received_quantity' => $line->net_received_quantity,
                'gross_received_quantity' => $line->gross_received_quantity,
                'net_received_quantity' => $line->net_received_quantity,
                'variance_quantity' => $variance,
                'unit_price' => $unitPrice,
                'weight_photo_path' => $line->weight_photo_path,
                'notes' => $line->notes,
            ];
        }, $dto->lines);

        $receipt = $this->receipts->create($attributes, $lines);

        return OperationResult::success($receipt, 'Goods receipt created successfully.');
    }

    /**
     * True when this receipt is raised against Purchase Material lines.
     *
     * Mixing the two anchors on one receipt is refused rather than silently resolved: a
     * receipt whose lines came from two different ordering documents has no single
     * supplier, no single company and no coherent ordered quantity.
     */
    private function isPurchaseMaterialAnchored(GoodsReceiptDTO $dto): bool
    {
        $withPm = 0;
        $withPo = 0;

        foreach ($dto->lines as $line) {
            if ($line->purchase_material_line_id !== null) {
                $withPm++;
            }
            if ($line->purchase_order_line_id !== null) {
                $withPo++;
            }
        }

        if ($withPm > 0 && $withPo > 0) {
            throw PurchaseMaterialReceivingException::mixedAnchors();
        }

        return $withPm > 0;
    }

    /**
     * Load every purchase-material line this receipt names, asserting each exists and that
     * the received product matches the ordered product.
     *
     * @return \Illuminate\Support\Collection<string, PurchaseMaterialLine>
     */
    private function resolvePurchaseMaterialLines(GoodsReceiptDTO $dto): \Illuminate\Support\Collection
    {
        $ids = array_values(array_filter(array_map(
            fn (GoodsReceiptLineDTO $l): ?string => $l->purchase_material_line_id,
            $dto->lines,
        )));

        $lines = PurchaseMaterialLine::query()
            ->with('purchaseMaterial')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($dto->lines as $dtoLine) {
            $id = $dtoLine->purchase_material_line_id;
            if ($id === null) {
                continue;
            }

            $pmLine = $lines->get($id);

            if ($pmLine === null) {
                throw PurchaseMaterialReceivingException::lineNotFound($id);
            }

            if ((string) $pmLine->product_id !== $dtoLine->product_id) {
                throw PurchaseMaterialReceivingException::productMismatch($id);
            }
        }

        return $lines;
    }

    /**
     * RD-1 — one receipt, one supplier, taken from the purchase material line.
     *
     * @param  \Illuminate\Support\Collection<string, PurchaseMaterialLine>  $pmLines
     */
    private function assertSingleSupplier(\Illuminate\Support\Collection $pmLines): void
    {
        $suppliers = [];

        foreach ($pmLines as $line) {
            if ($line->supplier_id === null) {
                throw PurchaseMaterialReceivingException::supplierMissing((string) $line->id);
            }
            $suppliers[(string) $line->supplier_id] = true;
        }

        if (count($suppliers) > 1) {
            throw PurchaseMaterialReceivingException::supplierMismatch();
        }
    }

    /**
     * The owning company of the Purchase being received against.
     *
     * @param  \Illuminate\Support\Collection<string, PurchaseMaterialLine>  $pmLines
     */
    private function purchaseMaterialCompanyId(\Illuminate\Support\Collection $pmLines): ?string
    {
        $companyId = $pmLines->first()?->purchaseMaterial?->company_id;

        return $companyId === null ? null : (string) $companyId;
    }

    /**
     * The receiving warehouse's company — the fallback ownership source.
     *
     * Read through the query builder, not the Warehouse model: `Warehouse` carries a tenant
     * global scope, and resolving ownership through a scope that is itself derived from the
     * actor would make the write path circular.
     */
    private function warehouseCompanyId(string $warehouseId): ?string
    {
        $value = DB::table('warehouses')->where('id', $warehouseId)->value('company_id');

        return $value === null ? null : (string) $value;
    }

    private function resolvePaymentDueDate(GoodsReceiptDTO $dto): ?string
    {
        // Manual override takes precedence
        if ($dto->payment_due_date !== null) {
            return $dto->payment_due_date;
        }

        // Auto-calculate from invoice date + payment terms
        if ($dto->supplier_invoice_date !== null && $dto->payment_terms_days !== null) {
            return Carbon::parse($dto->supplier_invoice_date)
                ->addDays($dto->payment_terms_days)
                ->toDateString();
        }

        return null;
    }
}
