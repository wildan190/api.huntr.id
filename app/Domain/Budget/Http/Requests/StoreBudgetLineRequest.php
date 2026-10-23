<?php

namespace App\Domain\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBudgetLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid'], 'budget_cycle_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:150'], 'department' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:100'], 'allocated_amount' => ['required', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'owner_id' => ['nullable', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
