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
            'po_id' => ['required', 'exists:purchase_orders,id'],
            'received_qty' => ['required', 'integer', 'min:1'],
            'handover_document_path' => ['required', 'string'],
        ];
    }
}
