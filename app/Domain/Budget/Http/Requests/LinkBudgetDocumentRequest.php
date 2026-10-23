<?php

namespace App\Domain\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkBudgetDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid'], 'rfq_id' => ['sometimes', 'required', 'uuid'], 'purchase_order_id' => ['sometimes', 'required', 'uuid'], 'amount' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:1000'], 'occurred_at' => ['nullable', 'date']];
    }
}
