<?php

namespace App\Domain\Wms\Http\Requests;

class SetReorderLevelRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + ['reorder_level' => ['required', 'numeric', 'min:0']];
    }
}
