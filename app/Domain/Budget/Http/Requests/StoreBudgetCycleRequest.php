<?php

namespace App\Domain\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'fiscal_year' => ['required', 'integer', 'min:2020', 'max:2100'], 'currency' => ['nullable', 'string', 'size:3'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after_or_equal:starts_at'], 'total_amount' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
