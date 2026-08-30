<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Domain\Models;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

class SupplierInvoice extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'invoice_number',
        'supplier_invoice_ref',
        'supplier_id',
        'warehouse_id',
        'company_id',
        'auto_purchase_id',
        'auto_receipt_id',
        'status',
        'invoice_date',
        'due_date',
        'delivery_date',
        'currency',
        'exchange_rate',
        'subtotal',
        'tax_total',
        'freight_amount',
        'additional_costs',
        'discount_amount',
        'grand_total',
        'payment_terms',
        'payment_terms_days',
        'payment_method',
        'notes',
        'internal_notes',
        'posting_log',
        'posting_error',
        'processing_started_at',
        'posted_by',
        'posted_at',
        'created_by',
    ];

    protected $casts = [
        'status' => SupplierInvoiceStatus::class,
        'invoice_date' => 'date',
        'due_date' => 'date',
        'delivery_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'freight_amount' => 'decimal:4',
        'additional_costs' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'posting_log' => 'array',
        'processing_started_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function autoPurchase(): BelongsTo
    {
        return $this->belongsTo(PurchaseMaterial::class, 'auto_purchase_id');
    }

    public function autoReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'auto_receipt_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class);
    }

    public function generateInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ym').'-';
        $last = static::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function recalculateTotals(): void
    {
        // line_total is TAX-INCLUSIVE (syncLines: qty*price + tax − line discount). The
        // old formula added tax_total again on top of the gross line_total, double-counting
        // tax in grand_total. Derive the tax-EXCLUSIVE net subtotal so the three figures are
        // internally consistent: subtotal (net) + tax + freight + additional − discount = total.
        $grossLineTotal = (float) $this->lines->sum('line_total');
        $taxTotal = (float) $this->lines->sum('tax_amount');
        $netSubtotal = round($grossLineTotal - $taxTotal, 4);

        $this->subtotal = $netSubtotal;
        $this->tax_total = $taxTotal;
        $this->grand_total = round(
            $netSubtotal + $taxTotal + $this->freight_amount + $this->additional_costs - $this->discount_amount,
            4,
        );
    }

    /**
     * B-2 — tenant isolation.
     *
     * This model carried NO tenant scope, and the posting endpoint took a raw string id
     * rather than route-model binding, so nothing between the route and the stock ledger
     * compared the document's company with the actor's. A Company A actor holding the
     * posting permission posted a Company B receipt over HTTP and received 200, moving 50
     * units into another company's warehouse.
     *
     * Verbatim the scope the four already-scoped models use (Order, Warehouse, Supplier,
     * ShippingPricingRule) — a foreign row becomes invisible, the repository lookup returns
     * null, and the existing not-found exception produces the 404 the certified ECOS tenant
     * contract expects. No new tenant mechanism is introduced.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $tenant = app(TenantOwnershipResolver::class);

            // Console, queue workers, seeders and migrations run with no actor.
            if (! $tenant->appliesTo()) {
                return;
            }

            // Cross-company access is granted by an is_system role, never by the
            // mere absence of a company. See TenantOwnershipResolver.
            if ($tenant->isUnrestricted()) {
                return;
            }

            $companyId = $tenant->companyId();

            // RC-6: a null company must close the query, not remove the filter.
            if ($companyId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('company_id', $companyId);
        });
    }
}
