<?php

return [
    'work_resources' => [
        'maintenance_request' => ['model' => \App\Modules\Maintenance\Models\MaintenanceRequest::class,'room_relation'=>'room', 'permission' => 'maintenance.process', 'view_permission'=>'maintenance.view','screen'=>'maintenance','link'=>'Xem yêu cầu sửa chữa'],
        'invoice_draft' => ['model' => \App\Modules\Billing\Models\Invoice::class,'room_relation'=>'rental.room', 'permission' => 'invoice.create', 'view_permission'=>'invoice.view','screen'=>'billing','link'=>'Xem hóa đơn nháp'],
        'payment_review' => ['model'=>\App\Modules\Billing\Models\PaymentRequest::class,'room_relation'=>'rental.room','permission'=>'payment.verify','view_permission'=>'payment.view','screen'=>'payments','link'=>'Xem khoản cần đối soát','states'=>['awaiting_payment','verification_pending','partial','exception']],
        'rental_negotiation' => ['model'=>\App\Modules\Rental\Models\RentalRequest::class,'room_relation'=>'room','permission'=>'rental.create','view_permission'=>'rental.view','screen'=>'rentals','link'=>'Xem yêu cầu thuê','states'=>['submitted','counter_offered']],
    ],
    'tabs' => [
        'team-permissions' => ['members' => 'Nhân viên', 'invitations' => 'Lời mời', 'groups' => 'Nhóm quyền', 'grants' => 'Quyền có thời hạn', 'handover' => 'Bàn giao công việc', 'history' => 'Lịch sử'],
        'access-control' => ['assignments' => 'Cấp quyền tài khoản', 'groups' => 'Nhóm quyền mẫu', 'catalog' => 'Danh mục quyền', 'history' => 'Lịch sử'],
        'profile-security' => ['profile' => 'Thông tin cá nhân', 'security' => 'Mật khẩu & bảo mật', 'access' => 'Quyền truy cập'],
        'admin-accounts' => ['accounts' => 'Danh sách tài khoản'],
    ],
    'descriptions' => [
        'team-permissions' => 'Quản lý nhân viên, nhà được giao và quyền truy cập tại khu đang chọn.',
        'access-control' => 'Cấp quyền phù hợp cho từng tài khoản và theo dõi các thay đổi.',
        'profile-security' => 'Cập nhật hồ sơ, bảo vệ tài khoản và xem các khu bạn đang tham gia.',
        'admin-accounts' => 'Tra cứu tài khoản và xử lý trạng thái truy cập.',
    ],
    'audit_labels' => [
        'permission.change.proposed' => 'Đề nghị thay đổi chính sách quyền', 'permission.change.approved' => 'Phê duyệt chính sách quyền', 'permission.change.applied' => 'Áp dụng chính sách quyền',
        'workspace.archived' => 'Lưu trữ khu quản lý',
        'group.change.proposed' => 'Đề nghị đổi nhóm quyền', 'group.change.approved' => 'Phê duyệt đổi nhóm quyền', 'group.change.applied' => 'Áp dụng đổi nhóm quyền', 'group.emergency_disabled' => 'Tạm ngừng nhóm quyền khẩn cấp', 'permission.dependency_revoked' => 'Thu hồi quyền theo nguồn cấp', 'work.owner_takeover' => 'Chủ quản lý nhận bàn giao',
        'membership.activated' => 'Kích hoạt nhân viên', 'membership.suspended' => 'Tạm ngừng nhân viên', 'work.assigned' => 'Phân công công việc',
        'group.assignment.save' => 'Gán nhóm quyền bổ sung', 'group.assignment.revoke' => 'Thu hồi nhóm quyền', 'system.settings' => 'Cập nhật cấu hình vận hành', 'invoice.export' => 'Xuất hóa đơn', 'payment.export' => 'Xuất thanh toán',
        'workspace.group.save' => 'Cập nhật nhóm quyền của khu quản lý',
        'membership.invited' => 'Tạo lời mời', 'membership.accepted' => 'Nhân viên tham gia', 'membership.updated' => 'Cập nhật quyền nhân viên', 'membership.ended' => 'Kết thúc quyền truy cập',
        'invitation.declined' => 'Từ chối lời mời', 'invitation.cancelled' => 'Hủy lời mời', 'invitation.renewed' => 'Tạo lại liên kết mời',
        'permission.granted' => 'Cấp quyền có thời hạn', 'permission.revoked' => 'Thu hồi quyền',
        'permission.save' => 'Cập nhật quyền', 'category.save' => 'Cập nhật danh mục', 'group.save' => 'Cập nhật nhóm quyền', 'account.group' => 'Gán nhóm tài khoản', 'global.grant' => 'Cấp quyền có thời hạn',
        'account.status' => 'Thay đổi trạng thái tài khoản',
    ],
];
