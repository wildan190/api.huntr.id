<?php

namespace App\Domain\CrowdBuy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
        ];
    }
}
