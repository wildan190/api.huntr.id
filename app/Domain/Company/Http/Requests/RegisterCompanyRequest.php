<?php

namespace App\Domain\Company\Http\Requests;

use App\Support\KeywordNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterCompanyRequest extends FormRequest
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

        if ($this->has('tax_id')) {
            $this->merge([
                'tax_id' => str_replace(['.', '-'], '', $this->input('tax_id')),
            ]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:buyer,vendor'],
            'user_id' => ['nullable', 'exists:users,id'],
            'country' => ['nullable', 'string', 'max:10'],
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
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string', 'max:100'],
            'industry_type' => ['nullable', 'string'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.name' => ['required', 'string'],
            'documents.*.type' => ['required', 'string'],
            'documents.*.file_path' => ['required', 'string'],
        ];

        // TIN/NPWP is required only for Indonesia
        $country = $this->input('country');
        if ($this->isIndonesia($country)) {
            $rules['tax_id'] = [
                'required',
                'string',
                'min:15',
                'max:16',
                // 1 NPWP can only have 1 Buyer workspace and 1 Vendor workspace
                Rule::unique('companies', 'tax_id')->where('type', $this->input('type')),
            ];
        } else {
            $rules['tax_id'] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        $type = $this->input('type');
        $typeLabel = $type === 'vendor' ? 'Vendor' : 'Buyer';
        return [
            'tax_id.unique' =>
                "NPWP/Tax ID ini sudah terdaftar sebagai perusahaan {$typeLabel}. " .
                "Anda tidak dapat mendaftarkan akun {$typeLabel} baru dengan NPWP yang sama. " .
                "Silakan masuk ke workspace yang sudah ada, atau daftarkan perusahaan ini dengan tipe yang berbeda.",
        ];
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
