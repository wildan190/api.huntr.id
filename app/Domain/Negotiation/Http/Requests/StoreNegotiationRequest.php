<?php

namespace App\Domain\Negotiation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNegotiationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'proposal_id' => 'required|exists:proposals,id',
            'payment_scheme' => 'nullable|string',
            'delivery_terms' => 'nullable|string',
            'buyer_remarks' => 'nullable|string',
            'items' => 'required|array',
            'items.*.proposal_item_id' => 'required',
            'items.*.negotiated_price' => 'required|numeric',
            'items.*.negotiated_qty' => 'required|integer',
        ];
    }
}
