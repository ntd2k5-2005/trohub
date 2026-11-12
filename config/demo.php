<?php

return [
    'password' => env('DEMO_PASSWORD', 'Abcd@1234'),
    'accounts' => [
        'tenant' => ['name' => 'Nguyễn An', 'group' => 'Người thuê'],
        'staff' => ['name' => 'Thùy Linh', 'group' => 'Quản lý vận hành'],
        'accountant' => ['name' => 'Ngọc Trâm', 'group' => 'Kế toán'],
        'maintenance' => ['name' => 'Nhân viên bảo trì', 'group' => 'Bảo trì'],
        'platform' => ['name' => 'Quản trị nền tảng', 'group' => 'Quản trị nền tảng'],
        'system' => ['name' => 'Quản trị hệ thống', 'group' => 'Quản trị hệ thống'],
        'owner' => ['name' => 'Minh Anh', 'group' => 'Người dùng'],
        'other' => ['name' => 'Chủ trọ khác', 'group' => 'Người dùng'],
    ],
];
