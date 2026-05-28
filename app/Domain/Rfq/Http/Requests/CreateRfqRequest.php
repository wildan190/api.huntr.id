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
            'title' => ['required', 'string'],
            'description' => ['required', 'string'],
            'items' => ['required', 'array'],
            'items.*.catalogue_id' => ['required', 'exists:catalogues,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.expected_date' => ['required', 'date'],
        ];
    }
}
