<?php

namespace App\Domain\Wms\Http\Requests;

class StoreWarehouseBinRequest extends WmsFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('warehouseId') && ! $this->has('warehouse_id')) {
            $this->merge(['warehouse_id' => $this->route('warehouseId')]);
        }
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

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
