<?php

namespace App\Domain\Auth\Http\Requests;

use App\Support\KeywordNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('keywords')) {
            $this->merge([
                'keywords' => KeywordNormalizer::normalize($this->input('keywords')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', 'exists:companies,id'],
            'item_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specifications' => ['sometimes', 'nullable', 'string'],
            'keywords' => ['sometimes', 'nullable', 'array'],
            'keywords.*' => ['string', 'max:100'],
            'uom' => ['sometimes', 'required', 'string', 'max:50'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'max:2048'],
        ];
    }
}
