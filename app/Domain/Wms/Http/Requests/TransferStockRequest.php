<?php

namespace App\Domain\Wms\Http\Requests;

class TransferStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'from_warehouse_id' => ['required', 'uuid', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:100'],
            'from_bin' => ['required', 'string', 'max:100'],
            'to_bin' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
