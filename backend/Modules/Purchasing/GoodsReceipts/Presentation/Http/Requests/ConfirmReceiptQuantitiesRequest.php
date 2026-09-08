<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmReceiptQuantitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.accepted_qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
