<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'whatsapp' => ['nullable', 'string'],
            'otp' => ['required', 'string'],
            'otp_token' => ['nullable', 'string'],
        ];
    }
}
