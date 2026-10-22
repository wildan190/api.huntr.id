<?php

namespace App\Domain\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApprovalFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'subject_type' => ['required', 'in:purchase_request,winner_approval,po_confirmation'],
            'currency' => ['nullable', 'string', 'size:3'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:12'],
            'steps.*.name' => ['nullable', 'string', 'max:120'],
            'steps.*.company_role_id' => ['required', 'uuid'],
            'steps.*.required_approvals' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
