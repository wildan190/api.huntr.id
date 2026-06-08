<?php

namespace App\Domain\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyNpwpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'npwp' => ['required', 'string', 'min:15', 'max:16'],
            'country' => ['required', 'string', 'max:10'],
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
