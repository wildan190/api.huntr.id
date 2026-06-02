<?php

namespace App\Domain\Company\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyNpwpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'npwp' => ['required', 'string', 'min:15', 'max:16'],
        ];
    }
}
