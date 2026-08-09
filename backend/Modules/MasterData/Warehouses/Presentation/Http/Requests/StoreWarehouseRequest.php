<?php

declare(strict_types=1);

namespace Modules\MasterData\Warehouses\Presentation\Http\Requests;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for creating a warehouse. Code is optional — auto-generated if omitted.
 */
final class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ownership may not be chosen by the client.
     *
     * TASK-GOLIVE-RC6-REPAIR-001 (RC-6): `company_id` arrived from the request
     * body while every read path filtered by the authenticated user's company,
     * so submitting another company's id produced a real record that the creator
     * could never see again. The submitted value must now match the company the
     * server considers authoritative, unless the actor is explicitly authorized
     * to operate across companies.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('company_id')) {
                return; // shape already rejected; do not stack messages
            }

            if (app(TenantOwnershipResolver::class)->owns((string) $this->input('company_id'))) {
                return;
            }

            $v->errors()->add(
                'company_id',
                'A warehouse may only be created for the company you belong to.',
            );
        });
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $companyId = (string) $this->input('company_id');

        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('warehouses', 'code')->where(
                    fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'),
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
