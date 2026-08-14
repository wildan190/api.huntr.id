<?php

namespace App\Domain\EFaktur\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VatInPrepopulatedRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tahun_pajak'  => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'masa_pajak'   => ['required', 'string', 'max:2'],
            'npwp_penjual' => ['nullable', 'string', 'max:20'],
            'nomor_faktur' => ['nullable', 'string', 'max:25'],
        ];
    }

    public function messages(): array
    {
        return [
            'tahun_pajak.required' => 'Tahun pajak wajib diisi.',
            'tahun_pajak.size'     => 'Tahun pajak harus 4 digit.',
            'tahun_pajak.regex'    => 'Tahun pajak harus berupa angka.',
            'masa_pajak.required'  => 'Masa pajak wajib diisi.',
        ];
    }
}
