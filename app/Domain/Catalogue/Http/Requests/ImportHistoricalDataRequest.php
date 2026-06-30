<?php

namespace App\Domain\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportHistoricalDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'csv' => ['required', 'file', 'mimes:csv,txt,xlsx,xls,xlsm,ods'],
        ];
    }
}
