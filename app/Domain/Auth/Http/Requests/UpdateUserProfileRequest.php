<?php

namespace App\Domain\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['sometimes', 'string', 'max:255'],
            'email'         => ['sometimes', 'nullable', 'email', 'max:255', 'unique:users,email,'.$this->user()?->id],
            'nik'           => ['nullable', 'string', 'max:20'],
            'npwp'          => ['nullable', 'string', 'max:25'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'birth_place'   => ['nullable', 'string', 'max:100'],
            'birth_date'    => ['nullable', 'date'],
            'job_title'     => ['nullable', 'string', 'max:100'],
            'country'       => ['nullable', 'string', 'max:100'],
            'province'      => ['nullable', 'string', 'max:100'],
            'city'          => ['nullable', 'string', 'max:100'],
            'district'      => ['nullable', 'string', 'max:100'],
            'postal_code'   => ['nullable', 'string', 'max:10'],
            'full_address'  => ['nullable', 'string', 'max:1000'],
            'profile_photo' => ['nullable', 'image', 'max:5120'], // max 5MB
        ];
    }
}
