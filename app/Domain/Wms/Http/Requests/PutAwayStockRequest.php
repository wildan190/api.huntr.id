<?php

namespace App\Domain\Wms\Http\Requests;

class PutAwayStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:100'],
            'from_bin_id' => ['required', 'uuid'],
            'to_bin_id' => ['required', 'uuid', 'different:from_bin_id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
