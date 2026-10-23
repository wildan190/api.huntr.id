<?php

namespace App\Domain\Wms\Http\Requests;

class ImportWarehouseStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'source_name' => ['nullable', 'string', 'max:120'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'mapping' => ['nullable', 'array'],
            'mode' => ['required', 'in:sync,add'],
            'create_missing_bins' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.source_row' => ['nullable', 'integer', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:100'],
            'lines.*.item_name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:30'],
            'lines.*.bin_code' => ['nullable', 'string', 'max:80'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reorder_level' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
