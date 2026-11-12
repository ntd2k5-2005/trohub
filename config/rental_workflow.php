<?php

return [
    'hold_hours' => 48,
    'extension_hours' => 24,
    'max_extensions' => 1,
    'exception_hours' => 24,
    'partial_deposit' => false,
    'deposit_terms' => 'Tiền cọc và điều kiện hoàn hoặc khấu trừ phải được hai bên thống nhất. Mọi khoản khấu trừ cần lý do và xác nhận; không tự động mất cọc khi hết hạn giữ phòng.',
    'statuses' => [
        'submitted' => 'Chờ xem xét', 'counter_offered' => 'Có đề nghị điều chỉnh', 'accepted' => 'Đã chấp thuận', 'rejected' => 'Đã từ chối', 'cancelled' => 'Đã hủy', 'expired' => 'Đã hết hạn',
        'holding' => 'Đang giữ phòng', 'deposit_confirmed' => 'Đã đủ cọc', 'review_pending' => 'Chờ đối soát', 'manual_review' => 'Cần quyết định xử lý', 'completed' => 'Đã bắt đầu thuê', 'released' => 'Đã giải phóng phòng',
    ],
];
