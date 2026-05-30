<?php

namespace App\Domain\Auth\Http\Requests;

use App\Support\WhatsappNumber;
use Illuminate\Foundation\Http\FormRequest;

class RegisterUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'whatsapp' => ['required', 'string', 'unique:users,whatsapp'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
