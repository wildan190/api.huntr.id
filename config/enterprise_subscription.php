<?php

return [
    // Enable after migrations and Midtrans configuration have been verified.
    'checkout_enabled' => (bool) env('ENTERPRISE_SUBSCRIPTION_CHECKOUT_ENABLED', false),
];
