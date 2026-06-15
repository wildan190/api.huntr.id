<?php

namespace App\Domain\Receipt\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_id'          => ['required', 'exists:purchase_orders,id'],
            'company_id'     => ['required'],
            'received_qty'   => ['nullable', 'integer', 'min:1'],
            'items_inspection' => ['nullable', 'array'],
            'items_inspection.*.po_item_id' => ['required', 'string'],
            'items_inspection.*.inventory_name' => ['required', 'string'],
            'items_inspection.*.ordered_qty' => ['required', 'numeric'],
            'items_inspection.*.received_qty' => ['required', 'numeric', 'min:0'],
            'items_inspection.*.rejected_qty' => ['required', 'numeric', 'min:0'],
            'items_inspection.*.condition' => ['nullable', 'string'],
            // handover_document_path is optional — backend defaults it automatically
        ];
    }
}
