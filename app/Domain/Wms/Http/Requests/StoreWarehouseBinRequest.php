<?php

namespace App\Domain\Wms\Http\Requests;

class StoreWarehouseBinRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:receiving,storage,picking,packing,staging,quarantine'],
            'pick_priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'capacity_units' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
