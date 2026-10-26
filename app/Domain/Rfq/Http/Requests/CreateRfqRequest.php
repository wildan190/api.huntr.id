<?php

namespace App\Domain\Rfq\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'procurement_mode' => ['nullable', 'in:tender,direct'],
            'procurement_type' => ['nullable', 'in:goods,services,mixed'],
            'project_type' => ['nullable', 'string', 'max:100'],
            'service_start_date' => ['nullable', 'date'],
            'service_end_date' => ['nullable', 'date', 'after_or_equal:service_start_date'],
            'service_location' => ['nullable', 'string', 'max:255'],
            'acceptance_criteria' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array'],
            'items.*.catalogue_id' => ['nullable', 'exists:catalogues,id'],
            'items.*.item_name' => ['nullable', 'string', 'max:255', 'required_without:items.*.catalogue_id'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.uom' => ['nullable', 'string', 'max:32'],
            'items.*.line_type' => ['nullable', 'in:goods,service'],
            'items.*.service_scope' => ['nullable', 'string', 'max:5000'],
            'items.*.acceptance_criteria' => ['nullable', 'string', 'max:3000'],
            'items.*.service_start_date' => ['nullable', 'date'],
            'items.*.service_end_date' => ['nullable', 'date', 'after_or_equal:items.*.service_start_date'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.expected_date' => ['required', 'date'],
            'items.*.estimated_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.budget' => ['nullable', 'numeric', 'min:0'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'document' => ['nullable', 'file', 'max:10240'],
            'delivery_point' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'uuid'],
            'department' => ['nullable', 'string', 'max:100'],
        ];
    }
}
