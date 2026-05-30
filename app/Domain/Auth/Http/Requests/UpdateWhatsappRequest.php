<?php

namespace App\Domain\Auth\Http\Requests;

use App\Support\WhatsappNumber;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsappRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('whatsapp')) {
            $this->merge([
                'whatsapp' => WhatsappNumber::normalize($this->input('whatsapp')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'whatsapp' => ['required', 'string', 'unique:users,whatsapp,' . $this->user()->id],
        ];
    }
}
