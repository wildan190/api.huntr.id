<?php

namespace App\Domain\EFaktur\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadEFakturRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tempat_penandatangan'   => ['required', 'string', 'max:100'],
            'npwp_nik_penandatangan' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'tempat_penandatangan.required'   => 'Tempat penandatangan wajib diisi.',
            'npwp_nik_penandatangan.required' => 'NPWP/NIK penandatangan wajib diisi.',
        ];
    }
}
