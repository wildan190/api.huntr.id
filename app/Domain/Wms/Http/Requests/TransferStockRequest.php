<?php

namespace App\Domain\Wms\Http\Requests;

class TransferStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            // A transfer may stay within one warehouse when an operator moves stock between bins from the map.
            'from_warehouse_id' => ['required', 'uuid'],
            'to_warehouse_id' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:100'],
            'from_bin' => ['required', 'string', 'max:100'],
            'to_bin' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
