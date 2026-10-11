<?php

namespace App\Domain\Wms\Http\Requests;

class UpdateWarehouseBinRequest extends WmsFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return $this->tenantRules() + [
            'name' => ['sometimes', 'string', 'max:160'],
            'type' => ['sometimes', 'in:receiving,storage,picking,packing,staging,quarantine'],
            'status' => ['sometimes', 'in:active,inactive'],
            'pick_priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'capacity_units' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
