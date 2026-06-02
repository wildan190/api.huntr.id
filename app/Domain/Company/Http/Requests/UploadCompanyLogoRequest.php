<?php

namespace App\Domain\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg', 'max:10240'],
        ];
    }
}
