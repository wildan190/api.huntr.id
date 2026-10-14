<?php

namespace App\Domain\CrowdBuy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCrowdBuyOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|array',
            'shipping_address.recipient_name' => 'required|string|max:150',
            'shipping_address.phone' => 'required|string|max:50',
            'shipping_address.address' => 'required|string',
            'shipping_address.city' => 'required|string|max:100',
            'shipping_address.province' => 'nullable|string|max:100',
            'shipping_address.postal_code' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
