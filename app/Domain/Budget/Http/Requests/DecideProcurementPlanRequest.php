<?php

namespace App\Domain\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideProcurementPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:2000']];
    }
}
