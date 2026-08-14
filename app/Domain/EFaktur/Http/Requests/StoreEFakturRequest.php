<?php

namespace App\Domain\EFaktur\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEFakturRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'bast_id'                    => ['required', 'uuid', 'exists:basts,id'],
            'signer_name'                => ['nullable', 'string', 'max:255'],
            'signer_jabatan'             => ['nullable', 'string', 'max:255'],
            'signer_npwp'                => ['nullable', 'string', 'max:20'],
            'signer_kota'                => ['nullable', 'string', 'max:100'],

            // Item overrides: user memilih kode barang & satuan DJP sendiri
            'items_override'             => ['nullable', 'array'],
            'items_override.*.id'        => ['required', 'string'],
            'items_override.*.nama'      => ['required', 'string', 'max:200'],
            'items_override.*.qty'       => ['required', 'numeric', 'min:0.001'],
            'items_override.*.unit_price'=> ['required', 'numeric', 'min:0'],
            'items_override.*.uom'       => ['required', 'string', 'max:20'],
            'items_override.*.kd_brg'    => ['required', 'string', 'max:10'],
            'items_override.*.satuan'    => ['required', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'bast_id.required'              => 'BAST ID wajib diisi.',
            'bast_id.uuid'                  => 'BAST ID harus berformat UUID.',
            'bast_id.exists'                => 'BAST tidak ditemukan.',
            'items_override.*.kd_brg.required'  => 'Kode barang DJP wajib dipilih untuk setiap item.',
            'items_override.*.satuan.required'  => 'Satuan wajib dipilih untuk setiap item.',
        ];
    }
}
