<?php

namespace App\Domain\Wms\Http\Requests;

class UpdateWarehouseOrderStatusRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules();
    }
}
