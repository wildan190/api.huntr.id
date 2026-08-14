<?php

namespace App\Domain\EFaktur\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VatInVerifyRequest extends FormRequest
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
}
