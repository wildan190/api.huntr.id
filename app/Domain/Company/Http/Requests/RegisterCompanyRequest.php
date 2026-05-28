<?php

namespace App\Domain\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:buyer,vendor'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tax_id' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'region' => ['nullable', 'string'],
            'provincy_country' => ['nullable', 'string'],
            'regency' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'zip_code' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'bank_name' => ['nullable', 'string'],
            'bank_account' => ['nullable', 'string'],
            'bank_account_name' => ['nullable', 'string'],
            'about' => ['nullable', 'string'],
            'industry_type' => ['nullable', 'string'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.name' => ['required', 'string'],
            'documents.*.type' => ['required', 'string'],
            'documents.*.file_path' => ['required', 'string'],
        ];
    }
}
