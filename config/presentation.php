<?php

return [
    // Display policy only. Data provenance and business authorization remain in their own modules.
    'lists'=>[
        'admin-accounts'=>['title'=>'Tài khoản','columns'=>['Trạng thái','Tham gia ngày']],
        'access-control'=>['title'=>'Quyền truy cập','columns'=>['Trạng thái','Số quyền','Thời gian']],
        'team-permissions'=>['title'=>'Nhân sự và phân quyền','columns'=>['Trạng thái','Nhóm quyền','Nhà được giao','Hết hạn']],
        'profile-security'=>['sections'=>['access'],'title'=>'Không gian và quyền truy cập','columns'=>['Nhà được giao','Hết hạn truy cập','Trạng thái khu','Số quyền']],
        'admin-dashboard'=>['sections'=>['plans','requests','payments','revenue','legacy','ownership','banking'], 'title'=>'Hồ sơ','columns'=>['Trạng thái','Gói','Số tiền','Thời gian']],
        'workspace-settings'=>['sections'=>['banking'],'title'=>'Tài khoản nhận tiền','columns'=>['Ngân hàng','Số tài khoản','Trạng thái','Phạm vi']],
        'subscription'=>['title'=>'Lịch sử gói dịch vụ','columns'=>['Gói','Trạng thái','Số tiền','Hết hạn']],
        'landlord-upgrade'=>['title'=>'Gói dịch vụ của bạn','columns'=>['Gói','Trạng thái','Số tiền','Hết hạn']],
        'properties'=>['title'=>'Nhà và phòng','columns'=>['Trạng thái','Giá thuê','Diện tích','Địa chỉ']],
        'workspace-listings'=>['title'=>'Tin cho thuê','columns'=>['Trạng thái','Giá thuê','Diện tích']],
        'rentals'=>['title'=>'Hồ sơ thuê','columns'=>['Người thuê','Trạng thái','Thời hạn','Giá thuê']],
        'billing'=>['title'=>'Hóa đơn','columns'=>['Còn phải trả','Trạng thái','Tổng phải thu','Đã thanh toán']],
        'my-invoices'=>['title'=>'Hóa đơn của tôi','columns'=>['Còn phải trả','Trạng thái','Tổng phải thu','Đã thanh toán']],
        'payments'=>['title'=>'Thanh toán','columns'=>['Còn phải trả','Trạng thái','Tổng phải thu','Đã thanh toán']],
        'utilities'=>['title'=>'Điện nước và dịch vụ','columns'=>['Chỉ số trước','Chỉ số mới','Mức sử dụng','Ghi chú']],
        'maintenance'=>['title'=>'Yêu cầu sửa chữa','columns'=>['Trạng thái','Phòng','Người phụ trách','Thời gian']],
        'connections'=>['title'=>'Liên hệ và lịch xem','columns'=>['Trạng thái','Lịch hẹn','Nhu cầu thuê']],
        'workspace-leads'=>['title'=>'Khách quan tâm','columns'=>['Trạng thái','Lịch hẹn','Nhu cầu thuê']],
        'moderation'=>['title'=>'Tin cần quản lý','columns'=>['Nhà trọ','Trạng thái','Phiên bản nội dung']],
        'moderation-history'=>['title'=>'Lịch sử xử lý','columns'=>['Thời gian']],
        'admin-reports'=>['title'=>'Báo cáo vi phạm','columns'=>['Trạng thái','Kết quả']],
        'my-reports'=>['title'=>'Báo cáo của tôi','columns'=>['Trạng thái','Kết quả']],
        'notifications'=>['title'=>'Thông báo','columns'=>['Trạng thái','Mức ưu tiên','Thời gian']],
        'iot-devices'=>['title'=>'Thiết bị','columns'=>['Sức khỏe thiết bị','Mức cảnh báo gần nhất','Lần nhận dữ liệu cuối']],
        'iot-incidents'=>['title'=>'Cảnh báo và sự cố','columns'=>['Xử lý','Mức cảnh báo','Thiết bị','Lúc phát sinh']],
        'room-safety-history'=>['title'=>'Lịch sử an toàn','columns'=>['Xử lý','Mức cảnh báo','Thiết bị','Lúc phát sinh']],
        'data-platform'=>['title'=>'Vận hành hệ thống','columns'=>['Cấu hình','Kết nối thực tế','Lần kiểm tra gần nhất']],
    ],
    'sections'=>[
        'team-permissions'=>[
            'groups'=>['title'=>'Nhóm quyền','columns'=>['Quyền trong nhóm','Trạng thái']],
        ],
        'admin-dashboard'=>[
            'requests'=>['title'=>'Yêu cầu đăng ký và gia hạn','primary_fact'=>'Người đăng ký','columns'=>['Số tiền yêu cầu','Thanh toán','Gói','Ngày tạo']],
            'payments'=>['title'=>'Thanh toán và đối soát','primary_fact'=>'Người đăng ký','columns'=>['Số tiền yêu cầu','Thanh toán','Gói','Xác nhận lúc']],
            'plans'=>['title'=>'Gói dịch vụ','columns'=>['Giá','Thời hạn','Tiếp nhận yêu cầu','Ngày phát hành']],
            'revenue'=>['title'=>'Giao dịch đã xác nhận','columns'=>['Gói','Số tiền','Ngày ghi nhận','Người ghi nhận']],
            'banking'=>['title'=>'Tài khoản ngân hàng nền tảng','columns'=>['Ngân hàng','Số tài khoản','Trạng thái','Mặc định']],
            'legacy'=>['title'=>'Hồ sơ đăng ký trước đây','columns'=>['Trạng thái','Hết hạn','Thanh toán']],
        ],
    ],
    'variants'=>[
        'admin-dashboard'=>[
            'requests'=>['subscriptions'=>['title'=>'Thuê bao','columns'=>['Gói hiện tại','Trạng thái','Hết hạn','Gói kỳ tiếp theo']]],
            'payments'=>['history'=>['title'=>'Lịch sử đối soát','columns'=>['Số tiền đã đối soát','Thanh toán','Ngày đối soát','Bắt đầu kỳ']]],
        ],
    ],
    'canonical_details'=>['Hồ sơ phòng','Xem hồ sơ thuê','Chi tiết hồ sơ','Xem chi tiết','Chi tiết giao dịch'],
    'column_limit'=>4,
    'preview_fact_limit'=>6,
    'quick_actions'=>['listing.status','work.takeover','notification.read'],
    'copy'=>[
        'Trình diễn quyền riêng: grant'=>'Phân công hỗ trợ theo dõi hóa đơn',
        'Trình diễn quyền riêng: expired'=>'Phân công theo dõi hóa đơn có thời hạn',
    ],
];
