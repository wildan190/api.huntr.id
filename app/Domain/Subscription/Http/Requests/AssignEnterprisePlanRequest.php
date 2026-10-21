<?php

namespace App\Domain\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignEnterprisePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enterprise_plan' => ['required', 'string', 'in:enterprise_starter,enterprise_growth,enterprise_pro'],
            'billing_cycle' => ['required', 'string', 'in:monthly,annual'],
        ];
    }
}
