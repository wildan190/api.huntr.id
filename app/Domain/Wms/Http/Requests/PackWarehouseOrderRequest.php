<?php

namespace App\Domain\Wms\Http\Requests;

class PackWarehouseOrderRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + ['packing_notes' => ['nullable', 'string', 'max:1000']];
    }
}
