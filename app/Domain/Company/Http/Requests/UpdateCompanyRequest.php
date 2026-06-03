<?php

namespace App\Domain\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'tax_id'            => ['nullable', 'string'],
            'country'           => ['nullable', 'string', 'max:10'],
            'email'             => ['nullable', 'email'],
            'phone'             => ['nullable', 'string'],
            'region'            => ['nullable', 'string'],
            'provincy_country'  => ['nullable', 'string'],
            'regency'           => ['nullable', 'string'],
            'city'              => ['nullable', 'string'],
            'zip_code'          => ['nullable', 'string'],
            'address'           => ['nullable', 'string'],
            'bank_name'         => ['nullable', 'string'],
            'bank_account'      => ['nullable', 'string'],
            'bank_account_name' => ['nullable', 'string'],
            'about'             => ['nullable', 'string'],
            'industry_type'     => ['nullable', 'string'],
        ];
    }
}
