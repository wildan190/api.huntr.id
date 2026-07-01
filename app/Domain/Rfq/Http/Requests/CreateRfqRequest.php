<?php

namespace App\Domain\Rfq\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*.catalogue_id' => ['required', 'exists:catalogues,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.expected_date' => ['required', 'date'],
            'items.*.estimated_price' => ['nullable', 'numeric', 'min:0'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'document' => ['nullable', 'file', 'max:10240'], // 10MB max
            'delivery_point' => ['nullable', 'string', 'max:255'], // Delivery location
        ];
    }
}
