<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Presentation\Http\Requests;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StorePurchaseMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ownership is the warehouse's, and may not be borrowed across companies.
     *
     * TASK-PROCUREMENT-MANUAL-REMEDIATION-001. `company_id` is resolved server-side
     * from the initiating warehouse, so a restricted actor must not be able to
     * raise a request against another company's warehouse (which would persist a
     * row they could never see again — the RC-6 failure mode). An is_system actor
     * operating across companies is unaffected.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('warehouse_id')) {
                return; // shape already rejected; do not stack messages
            }

            $warehouseCompanyId = DB::table('warehouses')
                ->where('id', (string) $this->input('warehouse_id'))
                ->value('company_id');

            if ($warehouseCompanyId === null) {
                return; // unowned warehouse — nothing to enforce
            }

            if (app(TenantOwnershipResolver::class)->owns((string) $warehouseCompanyId)) {
                return;
            }

            $v->errors()->add(
                'warehouse_id',
                'A purchase material request may only be raised for a warehouse in your company.',
            );
        });
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'channel_id' => ['nullable', 'string', 'max:100'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'required_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'record_type' => ['sometimes', 'string', 'in:material_request,purchase'],
            'source_type' => ['nullable', 'string', 'in:material_request,direct,reorder,ai,manual'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.requested_qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_label' => ['nullable', 'string', 'max:50'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
