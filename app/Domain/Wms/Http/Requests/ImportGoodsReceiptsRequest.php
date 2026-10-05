<?php

namespace App\Domain\Wms\Http\Requests;

class ImportGoodsReceiptsRequest extends WmsFormRequest
{
    public function rules(): array
    {
        return $this->tenantRules() + [
            'warehouse_id' => ['required', 'uuid'],
            'goods_receipt_ids' => ['nullable', 'array', 'max:500'],
            'goods_receipt_ids.*' => ['uuid'],
        ];
    }
}
