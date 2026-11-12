<?php

return [
    'page_size' => ['name' => 'Số mục trên mỗi trang', 'config' => 'business.pagination', 'min' => 5, 'max' => 50],
    'chat_interval' => ['name' => 'Chu kỳ cập nhật trò chuyện (mili giây)', 'config' => 'chat.poll_ms', 'min' => 1000, 'max' => 30000],
    'device_heartbeat' => ['name' => 'Chu kỳ trạng thái thiết bị mặc định (giây)', 'config' => 'business.heartbeat_seconds', 'min' => 10, 'max' => 300],
];
