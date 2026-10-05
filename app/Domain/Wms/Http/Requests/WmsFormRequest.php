<?php

namespace App\Domain\Wms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class WmsFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Company membership and app-install access are enforced by WmsContext.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('company_id') && $this->query('company_id')) {
            $this->merge(['company_id' => $this->query('company_id')]);
        }
    }

    protected function tenantRules(): array
    {
        return ['company_id' => ['required', 'uuid']];
    }
}
