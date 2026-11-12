<?php

return [
    // Platform payee is distinct from each landlord's scoped receiving accounts.
    'platform_payee' => [
        'bank_bin' => env('PLATFORM_BANK_BIN'),
        'bank_name' => env('PLATFORM_BANK_NAME'),
        'account_no' => env('PLATFORM_BANK_ACCOUNT_NUMBER'),
        'account_holder_name' => env('PLATFORM_BANK_ACCOUNT_HOLDER'),
    ],
    // New subscriptions opt in explicitly; existing workspace periods are not converted.
    'account_authority_enabled' => (bool) env('SUBSCRIPTION_ACCOUNT_AUTHORITY_ENABLED', true),
    'currency' => 'VND', 'quote_hours' => 24,
    'feature_labels' => ['workspace'=>'Khu quản lý','property'=>'Nhà trọ','room'=>'Phòng','listing'=>'Tin cho thuê','billing'=>'Kế toán','maintenance'=>'Sửa chữa','iot'=>'Thiết bị và an toàn','analytics'=>'Phân tích'],
    'feature_permissions' => ['property.'=>'property','room.'=>'room','listing.'=>'listing','invoice.'=>'billing','utility.'=>'billing','payment.'=>'billing','payment_request.'=>'billing','bank_account.'=>'billing','maintenance.'=>'maintenance','iot.'=>'iot','analytics.'=>'analytics'],
    'plans' => [
        'trial' => [
            'label' => 'Dùng thử', 'billing_cycle' => null, 'price' => 0,
            'features' => ['workspace', 'property', 'room', 'listing', 'billing', 'maintenance', 'iot'],
        ],
        'monthly' => [
            'label' => 'Theo tháng', 'billing_cycle' => 'monthly', 'price' => null,
            'features' => ['workspace', 'property', 'room', 'listing', 'billing', 'maintenance', 'iot'],
        ],
        'yearly' => [
            'label' => 'Theo năm', 'billing_cycle' => 'yearly', 'price' => null,
            'features' => ['workspace', 'property', 'room', 'listing', 'billing', 'maintenance', 'iot', 'analytics'],
        ],
    ],
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),
];
