<?php

return [
    'listing_review_required' => filter_var(env('LISTING_REVIEW_REQUIRED', false), FILTER_VALIDATE_BOOL),
    'room_types' => ['single' => 'Phòng trọ', 'mezzanine' => 'Phòng có gác', 'studio' => 'Studio', 'apartment' => 'Căn hộ/chung cư', 'whole_house' => 'Nhà nguyên căn', 'office' => 'Văn phòng', 'serviced_apartment' => 'Căn hộ dịch vụ'],
    'room_type_max_length' => 40, // Existing relational column width; no data conversion required.
    'amenities' => [
        'property' => ['parking' => 'Chỗ để xe', 'shared_wifi' => 'Wi-Fi chung', 'elevator' => 'Thang máy', 'laundry' => 'Khu giặt', 'gate' => 'Khóa cổng', 'fire_safety' => 'Thiết bị phòng cháy', 'common_camera' => 'Camera khu vực chung'],
        'room' => ['private_bathroom' => 'Vệ sinh riêng', 'mezzanine' => 'Gác lửng', 'air_conditioner' => 'Máy lạnh', 'water_heater' => 'Máy nước nóng', 'balcony' => 'Ban công', 'window' => 'Cửa sổ', 'bed' => 'Giường', 'desk' => 'Bàn ghế', 'wardrobe' => 'Tủ quần áo', 'kitchen' => 'Khu bếp', 'private_washer' => 'Máy giặt riêng', 'water_filter' => 'Máy lọc nước', 'refrigerator' => 'Tủ lạnh', 'internet' => 'Internet'],
    ],
    'custom_amenities_limit' => 20,
    'custom_amenity_length' => 60,
    'max_images' => 12,
    'fallback_image' => '/assets/brand/room-placeholder.svg',
    'demo_images' => [
        'https://kientructrangkim.com/wp-content/uploads/2022/11/thiet-ke-noi-that-phong-tro-35.jpg',
        'https://kientructrangkim.com/wp-content/uploads/2022/11/thiet-ke-noi-that-phong-tro-34.jpg',
    ],
];
