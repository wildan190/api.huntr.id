<?php

namespace App\Domain\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDirectPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'rfq_id' => ['required', 'uuid', 'exists:rfqs,id'],
            'vendor_name' => ['required', 'string', 'max:255'],
            'vendor_address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'purchase_category' => ['nullable', 'string', 'max:100'],
            'purchase_type' => ['nullable', 'string', 'max:100'],
            'expected_receiving_date' => ['nullable', 'date'],
        ];
    }
}
