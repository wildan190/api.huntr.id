<?php

namespace App\Domain\Wms\Http\Requests;

class AdjustStockRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:100'],
            'bin_location' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
