<?php

return [
    'personal_ownership_pages' => ['payments', 'maintenance', 'rentals', 'rental-detail'],
    'page_permissions' => ['workspace-settings' => ['workspace.view', 'bank_account.view', 'bank_account.create'], 'admin-dashboard' => ['account.view', 'subscription.approve', 'payment.view', 'payment.verify', 'subscription.view', 'subscription.revenue.view', 'subscription.plan.view', 'subscription.plan.edit', 'subscription.plan.publish', 'subscription.plan.manage', 'workspace.transfer.review', 'bank_account.view', 'bank_account.create', 'bank_account.update', 'bank_account.disable', 'bank_account.set_default', 'bank_account.delete'], 'data-platform' => ['data.view', 'data.project', 'data.replay', 'system.configure']],
    'admin_preview_limit' => 3,
    'admin_sections' => [
        'ownership' => ['label' => 'Hồ sơ chuyển quyền quản lý', 'permissions' => ['workspace.transfer.review'], 'group' => 2, 'icon' => 'users', 'nav'=>false],
        'business' => ['label' => 'Tổng quan kinh doanh', 'permissions' => ['subscription.revenue.view','subscription.view','payment.view','payment.verify','subscription.approve'], 'group' => 3, 'icon' => 'home'],
        'plans' => ['label' => 'Gói dịch vụ và hạn mức', 'permissions' => ['subscription.plan.manage','subscription.plan.view','subscription.plan.edit','subscription.plan.publish'], 'group' => 3, 'icon' => 'document'],
        'requests' => ['label' => 'Đăng ký và thuê bao', 'permissions' => ['subscription.view','subscription.approve'], 'group' => 3, 'icon' => 'wallet'],
        'payments' => ['label' => 'Thanh toán và đối soát', 'permissions' => ['payment.view','payment.verify','subscription.approve'], 'group' => 3, 'icon' => 'wallet'],
        'revenue' => ['label' => 'Doanh thu và báo cáo', 'permissions' => ['subscription.revenue.view'], 'group' => 3, 'icon' => 'wallet'],
        'order' => ['label' => 'Chi tiết đăng ký gói', 'permissions' => ['subscription.view','payment.view','payment.verify','subscription.approve','subscription.revenue.view'], 'group' => 3, 'icon' => 'document', 'nav' => false],
        'banking' => ['label' => 'Tài khoản ngân hàng nền tảng', 'permissions' => ['bank_account.view','bank_account.create','bank_account.update','bank_account.disable','bank_account.set_default','bank_account.delete'], 'group' => 3, 'icon' => 'wallet'],
        'legacy' => ['label' => 'Đối chiếu gói cũ', 'permissions' => ['subscription.approve'], 'group' => 3, 'icon' => 'document', 'nav' => false],
    ],
    'active_parents' => ['room-detail' => 'properties', 'rental-detail' => 'rentals', 'room-safety-history' => 'room-safety', 'moderation-history' => 'moderation', 'listing-detail' => 'marketplace-search', 'compare-listings' => 'favorites'],
    'icons' => [
        'personal-dashboard' => 'home', 'workspace-dashboard' => 'home', 'admin-dashboard' => 'home',
        'properties' => 'building', 'workspace-listings' => 'document', 'marketplace-search' => 'search',
        'conversations' => 'users', 'favorites' => 'heart', 'connections' => 'users', 'workspace-leads' => 'users', 'team-permissions' => 'users',
        'rentals' => 'document', 'billing' => 'wallet', 'my-invoices' => 'wallet', 'payments' => 'wallet', 'utilities' => 'wallet',
        'maintenance' => 'tool', 'iot-devices' => 'shield', 'iot-incidents' => 'shield', 'room-safety' => 'shield',
        'notifications' => 'bell', 'profile-security' => 'user', 'workspace-settings' => 'settings',
        'access-control' => 'shield', 'data-platform' => 'database', 'subscription' => 'wallet', 'landlord-upgrade' => 'building',
    ],
    'contexts' => [
        'public' => ['label' => 'Khám phá nhà trọ', 'home' => 'marketplace-search'],
        'personal' => ['label' => 'Tài khoản cá nhân', 'home' => 'personal-dashboard'],
        'workspace' => ['label' => 'Quản lý nhà trọ', 'home' => 'workspace-dashboard'],
        'platform-admin' => ['label' => 'Quản trị nền tảng', 'home' => 'admin-dashboard'],
        'system-admin' => ['label' => 'Quản trị hệ thống', 'home' => 'access-control'],
    ],

    // group_order của page được ánh xạ sang tên nhóm tại đây để module không phải
    // hard-code cấu trúc sidebar. Có thể đổi nhóm/menu mà không sửa view.
    'groups' => [
        'public' => [1 => 'Khám phá', 90 => 'Tài khoản'],
        'personal' => [
            1 => 'Tổng quan', 2 => 'Tìm phòng', 3 => 'Kết nối', 4 => 'Thuê & an toàn',
            5 => 'Tài chính', 6 => 'Hỗ trợ', 7 => 'Cộng đồng', 8 => 'Quản lý nhà trọ', 90 => 'Tài khoản',
        ],
        'workspace' => [
            1 => 'Tổng quan', 2 => 'Nhà & phòng', 3 => 'Khách & tin cho thuê',
            4 => 'Hồ sơ thuê', 5 => 'Tài chính', 6 => 'Vận hành', 7 => 'An toàn',
            8 => 'Nhân sự', 9 => 'Phân tích', 10 => 'Thiết lập', 90 => 'Thông báo',
        ],
        'platform-admin' => [1 => 'Tổng quan', 2 => 'Quản trị', 3 => 'Quản trị kinh doanh', 90 => 'Thông báo'],
        'system-admin' => [1 => 'Phân quyền', 2 => 'Vận hành dữ liệu', 8 => 'Dữ liệu', 9 => 'Phân tích'],
    ],
];
