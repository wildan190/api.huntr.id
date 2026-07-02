<?php

namespace App\Domain\Negotiation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondNegotiationRequest extends FormRequest
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
            'status' => 'required|in:accepted,declined',
            'vendor_remarks' => 'nullable|string',
        ];
    }
}
