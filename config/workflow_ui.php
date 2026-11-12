<?php

return [
    'steps' => [
        'property.save' => ['Thông tin nhà' => ['name', 'address', 'public_address', 'district'], 'Tiện ích và giới thiệu' => ['description', 'amenity_ids'], 'Hình ảnh' => ['image_urls']],
        'room.save' => ['Thông tin phòng' => ['property_id', 'name', 'floor', 'room_type', 'area', 'length', 'width', 'capacity'], 'Giá và điều kiện' => ['rent', 'deposit', 'available_from', 'rules'], 'Tiện nghi và hình ảnh' => ['amenity_ids', 'amenities', 'image_urls']],
        'listing.save' => ['Phòng và nội dung' => ['room_id', 'title', 'description'], 'Hình ảnh' => ['image_urls', 'cover', 'remove_cover']],
        'rental.create' => ['Phòng và người thuê' => ['room_id', 'tenant_name', 'tenant_email'], 'Thời hạn và điều khoản' => ['starts_at', 'ends_at', 'rent', 'deposit', 'terms']],
        'roommate.save' => ['Nhu cầu' => ['mode', 'room_id', 'district', 'people_needed'], 'Ngân sách và thời gian' => ['budget', 'budget_basis', 'move_in', 'duration_months'], 'Giới thiệu' => ['lifestyle', 'expires_at']],
    ],
];
