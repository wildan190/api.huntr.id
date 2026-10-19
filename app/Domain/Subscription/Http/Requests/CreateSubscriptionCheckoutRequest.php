<?php

namespace App\Domain\Subscription\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSubscriptionCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'purchase_type' => ['required', 'string', 'in:plan,addon,addons'],
            'catalog_version' => ['required', 'string', 'size:64'],
            'plan' => ['required_if:purchase_type,plan', 'string', 'in:enterprise_starter,enterprise_growth,enterprise_pro'],
            'billing_cycle' => ['required_if:purchase_type,plan', 'string', 'in:monthly,annual'],
            'addon_key' => ['required_if:purchase_type,addon', 'string', 'in:tax_documents,extra_user,extra_warehouse'],
            'quantity' => ['required_if:purchase_type,addon', 'integer', 'min:1', 'max:100'],
            'addons' => ['required_if:purchase_type,addons', 'array', 'max:3'],
            'addons.*.key' => ['required', 'string', 'distinct', 'in:tax_documents,extra_user,extra_warehouse'],
            'addons.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'payment_method' => ['required', 'string', 'in:bca_va,bni_va,bri_va,cimb_va,permata_va,mandiri_va'],
        ];
    }
}
