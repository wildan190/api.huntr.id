<?php

namespace App\Domain\CrowdBuy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FulfillOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:processing,shipped,delivered',
            'carrier' => 'required_if:status,shipped|nullable|string|max:100',
            'tracking_number' => 'required_if:status,shipped|nullable|string|max:150',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
