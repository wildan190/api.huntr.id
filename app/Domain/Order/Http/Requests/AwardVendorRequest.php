<?php

namespace App\Domain\Order\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AwardVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rfq_id' => ['required', 'exists:rfqs,id'],
            'proposal_id' => ['required', 'exists:proposals,id'],
            'manager_id' => ['required', 'exists:users,id'],
        ];
    }
}
