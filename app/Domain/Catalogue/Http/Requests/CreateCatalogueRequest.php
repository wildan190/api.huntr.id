<?php

namespace App\Domain\Catalogue\Http\Requests;

use App\Support\KeywordNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class CreateCatalogueRequest extends FormRequest
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
            'company_id'     => ['nullable', 'exists:companies,id'],
            'item_code'      => ['required', 'string', 'max:255'],
            'name'           => ['required', 'string', 'max:255'],
            'category'       => ['nullable', 'string', 'max:255'],
            'brand'          => ['nullable', 'string', 'max:255'],
            'specifications' => ['nullable', 'string'],
            'keywords'       => ['nullable', 'array'],
            'keywords.*'     => ['string', 'max:100'],
            'uom'            => ['required', 'string', 'max:50'],
            'price'          => ['required', 'numeric', 'min:0'],
            'image'          => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:5120'],
        ];
    }
}
