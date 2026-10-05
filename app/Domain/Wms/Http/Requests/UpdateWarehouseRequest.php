<?php

namespace App\Domain\Wms\Http\Requests;

class UpdateWarehouseRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ];
    }
}
