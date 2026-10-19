<?php

namespace App\Domain\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnterprisePricingRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'size:64'],
            'plans' => ['required', 'array', 'size:3'],
            'plans.*' => ['required', 'array:key,monthly_price,annual_price,users,tax_documents_per_year,support,warehouses'],
            'plans.*.key' => ['required', 'distinct', Rule::in(['enterprise_starter', 'enterprise_growth', 'enterprise_pro'])],
            'plans.*.monthly_price' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'plans.*.annual_price' => ['required', 'integer', 'min:1', 'max:12000000000'],
            'plans.*.users' => ['required', 'integer', 'min:1', 'max:100000'],
            'plans.*.tax_documents_per_year' => ['required', 'integer', 'min:0', 'max:10000000'],
            'plans.*.support' => ['required', 'string', 'max:100'],
            'plans.*.warehouses' => ['required', 'integer', 'min:0', 'max:100000'],
            'addons' => ['required', 'array', 'size:3'],
            'addons.*' => ['required', 'array:key,price'],
            'addons.*.key' => ['required', 'distinct', Rule::in(['tax_documents', 'extra_user', 'extra_warehouse'])],
            'addons.*.price' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ];
    }
}
