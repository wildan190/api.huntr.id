<?php

namespace App\Domain\Proposal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'rfq_id' => ['required', 'exists:rfqs,id'],
            'price_offer' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.rfq_item_id' => ['required', 'exists:rfq_items,id'],
            'items.*.price_offer' => ['required', 'numeric', 'min:0'],
            'delivery_days' => ['required', 'integer', 'min:1'],
            'warranty_months' => ['required', 'integer', 'min:0'],
            'payment_term' => ['nullable', 'string', 'in:7 days,14 days,30 days,60 days'],
            'document' => ['nullable', 'file', 'max:5120'], // Max 5MB
        ];
    }
}
