<?php

namespace App\Domain\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetPurchaseOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'per_page'   => ['integer', 'min:1', 'max:100'],
            'search'     => ['nullable', 'string', 'max:255'],
            'type'       => ['nullable', 'string', 'in:all,operational,historical'],
        ];
    }
}
