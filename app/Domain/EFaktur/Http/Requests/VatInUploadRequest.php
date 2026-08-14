<?php

namespace App\Domain\EFaktur\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VatInUploadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nomor_faktur'            => ['required', 'string', 'max:25'],
            'masa_pajak'              => ['required', 'string', 'max:2'],
            'tahun_pajak'             => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'konfirmasi_pengkreditan' => ['nullable', 'integer', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'nomor_faktur.required' => 'Nomor faktur wajib diisi.',
            'masa_pajak.required'   => 'Masa pajak wajib diisi.',
            'tahun_pajak.required'  => 'Tahun pajak wajib diisi.',
            'tahun_pajak.size'      => 'Tahun pajak harus 4 digit.',
        ];
    }
}
