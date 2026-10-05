<?php

namespace App\Domain\Wms\Http\Requests;

class AllocateStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'order_number' => ['required', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['nullable', 'string', 'max:100'],
            'lines.*.catalogue_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
