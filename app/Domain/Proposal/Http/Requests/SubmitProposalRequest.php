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
            'price_offer' => ['required', 'numeric', 'min:0'],
            'delivery_days' => ['required', 'integer', 'min:1'],
            'warranty_months' => ['required', 'integer', 'min:0'],
        ];
    }
}
