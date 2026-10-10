<?php

namespace App\Domain\Wms\Http\Requests;

class StoreWarehouseRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
            'capacity_units' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
