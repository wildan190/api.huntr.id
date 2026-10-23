<?php

namespace App\Domain\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProcurementPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid'], 'budget_cycle_id' => ['required', 'uuid'], 'budget_line_id' => ['nullable', 'uuid'], 'title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:3000'], 'department' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:100'], 'priority' => ['nullable', 'in:low,normal,high,critical'], 'currency' => ['nullable', 'string', 'size:3'], 'planned_amount' => ['required', 'numeric', 'min:0'], 'planned_start_at' => ['nullable', 'date'], 'planned_end_at' => ['nullable', 'date', 'after_or_equal:planned_start_at']];
    }
}
