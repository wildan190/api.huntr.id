<?php

namespace App\Domain\Subscription\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionCheckout extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'company_subscription_id', 'order_id', 'enterprise_plan',
        'billing_cycle', 'amount', 'payment_method', 'status', 'payment_info', 'provider_response',
        'purchase_type', 'addon_key', 'quantity',
        'billing_email', 'billing_email_sent_at', 'item_snapshot', 'addons_snapshot',
    ];

    protected $casts = [
        'amount' => 'integer',
        'quantity' => 'integer',
        'payment_info' => 'array',
        'provider_response' => 'array',
        'billing_email_sent_at' => 'datetime',
        'item_snapshot' => 'array',
        'addons_snapshot' => 'array',
    ];
}
