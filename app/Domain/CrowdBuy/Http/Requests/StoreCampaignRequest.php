<?php

namespace App\Domain\CrowdBuy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'catalogue_id' => 'nullable|uuid|exists:catalogues,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|max:100',
            'unit' => 'required|string|max:30',
            'base_price' => 'required|numeric|min:1',
            'target_quantity' => 'required|integer|min:1',
            'max_quantity' => 'nullable|integer|gte:target_quantity',
            'min_order_qty' => 'required|integer|min:1',
            'max_order_qty' => 'nullable|integer|gte:min_order_qty',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'lead_time_days' => 'nullable|integer|min:1',
            'delivery_terms' => 'nullable|string',
            'return_policy' => 'nullable|string',
            'image_path' => 'nullable|string',
            'price_tiers' => 'nullable|array',
            'price_tiers.*.min_quantity' => 'required_with:price_tiers|integer|min:1',
            'price_tiers.*.max_quantity' => 'nullable|integer',
            'price_tiers.*.unit_price' => 'required_with:price_tiers|numeric|min:1',
        ];
    }
}
