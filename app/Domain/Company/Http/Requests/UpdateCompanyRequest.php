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
        $rules = [
            'name'              => ['required', 'string', 'max:255'],
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

        // TIN/NPWP is required only for Indonesia
        $country = $this->input('country');
        if ($this->isIndonesia($country)) {
            $rules['tax_id'] = ['required', 'string', 'min:15', 'max:16'];
        } else {
            $rules['tax_id'] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * Check if the country is Indonesia.
     *
     * @param string|null $country
     * @return bool
     */
    private function isIndonesia(?string $country): bool
    {
        if (!$country) {
            return false;
        }

        $countryCode = strtoupper(trim($country));
        return in_array($countryCode, ['ID', 'INDONESIA']);
    }
}
