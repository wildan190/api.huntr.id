<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required_without:login', 'string'],
            'login' => ['required_without:email', 'string'],
            'password' => ['required', 'string'],
            'remember_me' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('email') && $this->filled('login')) {
            $this->merge(['email' => $this->input('login')]);
        }
    }
}
