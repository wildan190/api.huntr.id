<?php

namespace App\Domain\Wms\Http\Requests;

use Illuminate\Validation\Validator;

class ReceiveStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sku' => ['nullable', 'string', 'max:100'],
            'lines.*.catalogue_id' => ['nullable', 'uuid'],
            'lines.*.item_name' => ['nullable', 'string', 'max:255'],
            'lines.*.uom' => ['nullable', 'string', 'max:30'],
            'lines.*.received_quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.accepted_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.rejected_quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.condition' => ['required', 'in:good,damaged,short,other'],
            'lines.*.inspection_notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:120'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:160'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('lines', []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                if (empty($line['sku']) && empty($line['catalogue_id'])) {
                    $validator->errors()->add("lines.{$index}.sku", 'Pilih item katalog atau isi SKU.');
                }
                $received = (float) ($line['received_quantity'] ?? 0);
                $accepted = (float) ($line['accepted_quantity'] ?? 0);
                $rejected = (float) ($line['rejected_quantity'] ?? 0);
                if (abs($received - ($accepted + $rejected)) > 0.0005) {
                    $validator->errors()->add("lines.{$index}.accepted_quantity", 'Jumlah diterima harus sama dengan jumlah diterima baik ditambah jumlah ditolak.');
                }
            }
        });
    }
}
