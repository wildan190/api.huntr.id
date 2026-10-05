<?php

namespace App\Domain\Wms\Http\Requests;

class ShipWarehouseOrderRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'carrier' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:160'],
        ];
    }
}
