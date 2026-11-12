<?php

// Finite catalogs and defaults, never role-name checks or wildcard authority.
$catalog = [];
$add = function (string $module, string $category, string $resource, array $actions, array $scopes, bool $delegable = true, string $condition = '') use (&$catalog) {
    foreach ($actions as $action => $name) {
        $catalog[$resource.'.'.$action] = compact('module', 'category', 'name', 'scopes', 'delegable', 'condition') + ['action' => $action];
    }
};
$platform = ['platform'];
$add('Workspace', 'Quản trị nền tảng', 'workspace.transfer', ['review' => 'Thẩm tra hồ sơ bàn giao quyền quản lý'], $platform, false);
$add('Subscription', 'Gói dịch vụ', 'subscription.plan', ['manage' => 'Quản lý phiên bản gói dịch vụ'], $platform, false);
// Fine-grained capabilities; the existing plan.manage authority remains compatible.
$add('Subscription', 'Gói dịch vụ', 'subscription.plan', ['view'=>'Xem cấu hình gói', 'edit'=>'Soạn cấu hình gói', 'publish'=>'Phát hành phiên bản gói'], $platform);
$add('Subscription', 'Gói dịch vụ', 'subscription', ['view'=>'Xem đăng ký và thuê bao nền tảng'], $platform);
$add('Subscription', 'Kế toán', 'subscription.revenue', ['view'=>'Xem tiền gói đã nhận', 'export'=>'Xuất báo cáo tiền gói'], $platform);
$add('Billing', 'Kế toán', 'bank_account', ['delete'=>'Xóa tài khoản ngân hàng nền tảng chưa sử dụng'], $platform);
$managed = ['platform', 'workspace', 'property', 'room'];
$own = ['personal'];
$both = [...$managed, 'personal'];
$add('AccessControl', 'Phân quyền', 'access', ['view' => 'Xem phân quyền', 'catalog.edit' => 'Quản lý danh mục quyền', 'group.create' => 'Tạo nhóm quyền', 'group.update' => 'Sửa nhóm quyền', 'group.disable' => 'Ngừng nhóm quyền', 'assign' => 'Gán nhóm cho tài khoản', 'delegate' => 'Cấp quyền toàn cục', 'revoke' => 'Thu hồi quyền toàn cục', 'audit.view' => 'Xem lịch sử phân quyền', 'bootstrap' => 'Quản trị cấp cao'], $platform, false, 'Authority hiện hành, tập quyền con, không tự nâng quyền, bảo vệ quản trị viên cuối cùng.');
$add('Identity', 'Tài khoản', 'account', ['view' => 'Xem tài khoản', 'lock' => 'Khóa và mở khóa tài khoản'], $platform, false, 'Không tự khóa; không khóa người có authority cao hơn; giữ lịch sử.');
$add('DataPlatform', 'Vận hành hệ thống', 'data', ['view' => 'Xem tình trạng dữ liệu', 'project' => 'Đồng bộ dữ liệu', 'replay' => 'Khôi phục dữ liệu dẫn xuất'], $platform, false, 'Chỉ event được phép, không replay tài nguyên đã purge.');
$add('Admin', 'Vận hành hệ thống', 'system', ['configure' => 'Quản lý cấu hình vận hành'], $platform, false, 'Chỉ cấu hình được cho phép; không sửa secret hoặc policy từ frontend.');
$add('Workspace', 'Khu quản lý', 'workspace', ['view' => 'Xem khu quản lý', 'update' => 'Sửa khu quản lý'], $managed);
$add('Workspace', 'Khu quản lý', 'workspace', ['view_all' => 'Truy cập các khu quản lý toàn nền tảng'], $platform, false, 'Vẫn phải có quyền thao tác và thỏa điều kiện dữ liệu/gói.');
$add('Workspace', 'Cá nhân', 'workspace', ['create' => 'Mở khu quản lý của mình'], $own, false, 'Giới hạn dùng thử; gói trả phí được kích hoạt sau đối soát.');
$add('Workspace', 'Nhân sự', 'member', ['view' => 'Xem nhân viên', 'invite' => 'Mời nhân viên', 'update' => 'Sửa thông tin nhân viên', 'end' => 'Ngừng quyền làm việc', 'delegate' => 'Phân quyền trong khu quản lý', 'group.create' => 'Tạo nhóm quyền của khu quản lý', 'group.update' => 'Sửa nhóm quyền của khu quản lý'], $managed, true, 'Không vượt scope/hạn/tập quyền được ủy quyền; không tự sửa quyền.');
$add('Property', 'Nhà và phòng', 'property', ['view' => 'Xem nhà và phòng', 'create' => 'Thêm khu trọ', 'update' => 'Sửa khu trọ'], $managed);
$add('Property', 'Nhà và phòng', 'property', ['move' => 'Chuyển nhà sang khu quản lý khác'], $managed);
$add('Property', 'Nhà và phòng', 'room', ['create' => 'Thêm phòng', 'update' => 'Sửa phòng', 'deactivate' => 'Ngừng sử dụng phòng'], $managed, true, 'Quota của gói; không ngừng phòng còn người thuê.');
$add('Listing', 'Tin cho thuê', 'listing', ['view' => 'Xem tin trong khu quản lý', 'create' => 'Soạn tin cho thuê', 'update' => 'Sửa tin cho thuê', 'publish' => 'Đăng tin cho thuê', 'pause' => 'Ẩn hoặc đóng tin cho thuê'], $managed, true, 'Phòng/nhà/gói còn khả dụng; giữ lịch sử.');
$add('Trust', 'Quản trị nền tảng', 'listing', ['moderate' => 'Kiểm duyệt tin'], $platform, false);
$add('Connection', 'Khách hàng', 'connection', ['view' => 'Xem khách quan tâm', 'manage' => 'Xử lý liên hệ và lịch xem'], $managed);
$add('Rental', 'Hồ sơ thuê', 'rental', ['view' => 'Xem hồ sơ thuê', 'create' => 'Lập hồ sơ thuê', 'activate' => 'Kích hoạt thuê', 'renew' => 'Gia hạn thuê', 'move_out' => 'Xử lý trả phòng', 'close' => 'Hoàn tất trả phòng', 'cancel' => 'Hủy hồ sơ thuê chưa kích hoạt', 'occupant' => 'Quản lý người cư trú'], $both, true, 'Thuê đúng người/phòng; tenant không thực hiện thao tác quản lý; Paid/trạng thái/bàn giao/công nợ.');
$add('Rental', 'Hồ sơ thuê', 'deposit', ['record' => 'Ghi nhận cọc', 'settle' => 'Quyết toán cọc'], $managed, true, 'Một lần, có lý do khấu trừ; Paid và workflow hợp lệ.');
$add('Rental', 'Hồ sơ thuê', 'deposit', ['waive' => 'Miễn tiền cọc trước khi nhận tiền'], $managed, true, 'Không xóa tiền đã nhận; cần người thuê xác nhận lại điều khoản.');
$add('Billing', 'Kế toán', 'invoice', ['view' => 'Xem hóa đơn', 'create' => 'Lập hóa đơn', 'issue' => 'Phát hành hóa đơn', 'adjust' => 'Điều chỉnh hóa đơn', 'export' => 'Xuất hóa đơn'], $both, true, 'Tenant chỉ xem/xuất dữ liệu của mình; quản lý cần Paid, kỳ hợp lệ và snapshot phí.');
$add('Billing', 'Kế toán', 'utility', ['rate' => 'Thiết lập đơn giá dịch vụ', 'record' => 'Ghi chỉ số điện nước', 'view' => 'Xem lịch sử điện nước'], $managed, true, 'Đơn giá đúng kỳ; không ghi đè chỉ số đã chốt.');
$add('Billing', 'Kế toán', 'payment', ['record' => 'Ghi nhận thanh toán', 'view' => 'Xem thanh toán và công nợ', 'export' => 'Xuất lịch sử thanh toán'], $both, true, 'Tenant chỉ đọc/xuất của mình; ghi nhận cần Paid, invoice issued, idempotency và không trả quá nợ.');
$add('Billing', 'Kế toán', 'bank_account', ['view' => 'Xem tài khoản nhận tiền', 'create' => 'Thêm tài khoản nhận tiền', 'update' => 'Sửa tài khoản nhận tiền', 'disable' => 'Ngừng tài khoản nhận tiền', 'set_default' => 'Chọn tài khoản nhận tiền mặc định'], ['platform', 'workspace', 'property'], true, 'Đúng phạm vi; không thay snapshot các yêu cầu đã phát hành.');
$add('Billing', 'Kế toán', 'payment_request', ['issue' => 'Phát hành yêu cầu chuyển khoản'], $managed, true, 'Nghĩa vụ hợp lệ, người nhận và số tiền do máy chủ ấn định.');
$add('Billing', 'Kế toán', 'payment', ['verify' => 'Xác nhận tiền sau đối soát'], $managed, true, 'Đã kiểm tiền thực nhận; không dùng QR hoặc ảnh chứng từ làm xác nhận tự động.');
$add('Rental', 'Hồ sơ thuê', 'reservation', ['extend' => 'Gia hạn giữ phòng', 'resolve' => 'Xử lý giữ phòng và ngoại lệ'], $managed, true, 'Không giữ trùng; thay đổi phải có lý do và nhật ký.');
$add('Maintenance', 'Sửa chữa', 'maintenance', ['view' => 'Xem yêu cầu sửa chữa', 'create' => 'Báo sự cố nơi ở', 'comment' => 'Bổ sung thông tin sự cố', 'receive' => 'Tiếp nhận sửa chữa', 'process' => 'Cập nhật xử lý sửa chữa', 'close' => 'Hoàn tất sửa chữa'], $both, true, 'Tenant báo phòng đang thuê; reporter hoặc staff đúng scope; transition hợp lệ.');
$add('IoT', 'Thiết bị và an toàn', 'iot', ['view' => 'Xem thiết bị và an toàn', 'register' => 'Thêm thiết bị an toàn', 'move' => 'Chuyển phòng của thiết bị', 'configure' => 'Cấu hình thiết bị', 'ack' => 'Tiếp nhận cảnh báo', 'close' => 'Đóng sự cố an toàn', 'log.view' => 'Xem nhật ký an toàn'], $both, true, 'Tenant chỉ đọc phòng đang thuê; cấu hình theo loại/capability; không tắt an toàn cục bộ; thiết bị còn nguy hiểm không đóng.');
$add('Analytics', 'Phân tích', 'analytics', ['view' => 'Xem phân tích'], $managed, true, 'Feature/gói bật; không sửa nguồn nghiệp vụ.');
$add('Subscription', 'Gói dịch vụ', 'subscription', ['manage' => 'Quản lý gói dịch vụ'], ['platform', 'workspace'], false, 'Chủ sở hữu và gói hiện hành; không giao cho staff mặc định.');
$add('Subscription', 'Quản trị nền tảng', 'subscription', ['approve' => 'Đối soát gói dịch vụ'], $platform, false);
$add('Trust', 'Quản trị nền tảng', 'report', ['manage' => 'Điều tra và xử lý báo cáo'], $platform, false);
$add('Identity', 'Cá nhân', 'profile', ['update' => 'Sửa hồ sơ cá nhân', 'password' => 'Đổi mật khẩu cá nhân'], $own, false, 'Chỉ tài khoản của mình; đổi mật khẩu cần credential hiện tại.');
$add('Workspace', 'Cá nhân', 'membership', ['leave' => 'Rời khu quản lý của mình'], $own, false, 'Chỉ kết thúc lần tham gia của chính mình; chuyển công việc sang bàn giao, giữ nghĩa vụ.');
$add('Listing', 'Tìm trọ', 'favorite', ['manage' => 'Lưu và so sánh trọ'], $own, false);
$add('Connection', 'Tìm trọ', 'contact', ['create' => 'Liên hệ tin cho thuê', 'view' => 'Xem liên hệ của mình', 'schedule' => 'Hẹn và phản hồi lịch xem của mình'], $own, false, 'Đúng bên tham gia; không tự xác nhận lịch mình đề xuất.');
$add('Chat', 'Trò chuyện', 'chat', ['view' => 'Xem trò chuyện', 'send' => 'Gửi tin nhắn'], $both, true, 'ChatContext xác thực đúng bên và scope mỗi request.');
$add('Rental', 'Hồ sơ thuê', 'tenant', ['confirm' => 'Xác nhận hồ sơ thuê của mình', 'move_out' => 'Đề nghị trả phòng'], $own, false);
$add('Roommate', 'Ở ghép', 'roommate', ['view' => 'Tìm và xem hồ sơ ở ghép', 'create' => 'Đăng nhu cầu ở ghép', 'update' => 'Sửa nhu cầu ở ghép', 'close' => 'Đóng nhu cầu ở ghép', 'connect' => 'Gửi kết nối ở ghép', 'respond' => 'Phản hồi kết nối ở ghép'], $own, false, 'Own need/request; open/expiry/visibility; không tự kết nối.');
$add('Trust', 'Cộng đồng', 'community', ['report' => 'Báo cáo vi phạm', 'report.view' => 'Xem báo cáo của mình', 'review' => 'Đánh giá sau thuê'], $own, false, 'Review của tenant trên thuê đã closed, một review/rental.');
$add('Notification', 'Thông báo', 'notification', ['view' => 'Xem thông báo', 'read' => 'Đánh dấu thông báo đã đọc'], $own, false);

$legacy = [
    'access.manage' => ['access.view', 'access.catalog.edit', 'access.group.create', 'access.group.update', 'access.group.disable', 'access.assign', 'access.delegate', 'access.revoke', 'access.audit.view'],
    'account.manage' => ['account.view', 'account.lock'], 'data.manage' => ['data.view', 'data.project', 'data.replay', 'system.configure'],
    'property.manage' => ['property.create', 'property.update', 'room.create', 'room.update', 'room.deactivate'],
    'listing.manage' => ['listing.view', 'listing.create', 'listing.update', 'listing.publish', 'listing.pause'],
    'rental.manage' => ['rental.create', 'rental.activate', 'rental.renew', 'rental.move_out', 'rental.close', 'rental.cancel', 'rental.occupant', 'deposit.record', 'deposit.settle'],
    'invoice.manage' => ['invoice.create', 'invoice.issue', 'invoice.adjust', 'utility.rate', 'utility.record', 'utility.view'],
    'maintenance.manage' => ['maintenance.receive', 'maintenance.process', 'maintenance.close', 'maintenance.comment'],
    'iot.manage' => ['iot.register', 'iot.move', 'iot.configure'],
    'member.manage' => ['member.view', 'member.invite', 'member.update', 'member.end', 'member.delegate', 'member.group.create', 'member.group.update'],
];
$ordinary = ['workspace.create', 'profile.update', 'profile.password', 'favorite.manage', 'contact.create', 'contact.view', 'contact.schedule', 'chat.view', 'chat.send', 'notification.view', 'notification.read', 'roommate.view', 'roommate.create', 'roommate.update', 'roommate.close', 'roommate.connect', 'roommate.respond', 'community.report', 'community.report.view'];
$tenant = ['tenant.confirm', 'tenant.move_out', 'rental.view', 'invoice.view', 'invoice.export', 'payment.view', 'payment.export', 'maintenance.view', 'maintenance.create', 'maintenance.comment', 'iot.view', 'iot.log.view', 'community.review'];
$workspaceCodes = array_keys(array_filter($catalog, fn ($p) => in_array('workspace', $p['scopes'], true)));
$owner = array_values(array_filter($workspaceCodes, fn ($p) => ! in_array($p, ['listing.moderate', 'subscription.approve', 'report.manage'], true)));
foreach ($catalog as $code => &$p) {
    if (str_starts_with($code, 'rental.') && $code !== 'rental.view') {
        $p['scopes'] = $managed;
    }
    if (in_array($code, ['invoice.create', 'invoice.issue', 'invoice.adjust', 'payment.record', 'maintenance.receive', 'maintenance.process', 'maintenance.close', 'iot.register', 'iot.move', 'iot.close'], true)) {
        $p['scopes'] = $managed;
    }
}
unset($p);
$catalog['property.create']['scopes'] = ['platform', 'workspace'];
$catalog['property.update']['scopes'] = ['platform', 'workspace', 'property'];
$catalog['room.create']['scopes'] = ['platform', 'workspace', 'property'];
$catalog['workspace.update']['scopes'] = ['platform', 'workspace'];
$catalog['utility.rate']['scopes'] = ['platform', 'workspace', 'property'];
$catalog['bank_account.set_default']['scopes'] = $managed;
$catalog['analytics.view']['scopes'] = ['platform', 'workspace'];
$groups = [
    'supreme' => ['name' => 'Quản trị tối cao', 'scope' => 'global', 'permissions' => array_keys($catalog), 'protected' => true],
    'platform' => ['name' => 'Quản trị nền tảng', 'scope' => 'global', 'permissions' => ['account.view', 'account.lock', 'listing.moderate', 'report.manage', 'subscription.approve']],
    'system' => ['name' => 'Quản trị hệ thống', 'scope' => 'global', 'permissions' => $legacy['data.manage']],
    'owner' => ['name' => 'Chủ trọ', 'scope' => 'workspace', 'permissions' => $owner],
    'manager' => ['name' => 'Quản lý vận hành', 'scope' => 'workspace', 'permissions' => ['workspace.view', 'property.view', ...array_diff($legacy['property.manage'], ['property.create']), ...$legacy['listing.manage'], 'connection.view', 'connection.manage', 'rental.view', ...$legacy['rental.manage'], 'maintenance.view', ...$legacy['maintenance.manage'], 'chat.view', 'chat.send']],
    'accountant' => ['name' => 'Kế toán', 'scope' => 'workspace', 'permissions' => ['workspace.view', 'rental.view', 'invoice.view', ...$legacy['invoice.manage'], 'invoice.export', 'payment.record', 'payment.view', 'payment.export']],
    'technician' => ['name' => 'Bảo trì', 'scope' => 'workspace', 'permissions' => ['workspace.view', 'property.view', 'maintenance.view', 'maintenance.create', ...$legacy['maintenance.manage'], 'iot.view', ...$legacy['iot.manage'], 'iot.ack', 'iot.close', 'iot.log.view']],
    'tenant' => ['name' => 'Người thuê', 'scope' => 'personal', 'permissions' => $tenant],
    'ordinary' => ['name' => 'Người dùng', 'scope' => 'personal', 'permissions' => $ordinary],
    'custom' => ['name' => 'Tùy chỉnh', 'scope' => 'workspace', 'permissions' => []],
];

return [
    'new_commercial_permissions' => ['property.create', 'room.create', 'listing.create', 'listing.publish', 'rental.create'],
    'workspace_archive_permissions' => ['workspace.update', 'member.update'],
    'delegation' => ['default_max_depth' => 1, 'max_supported_depth' => 32],
    'group_change' => ['independent_approvals' => 2, 'high_risk_permissions' => ['member.delegate', 'member.update', 'member.end', 'payment.verify', 'payment.record', 'deposit.record', 'deposit.settle', 'access.assign', 'access.delegate', 'iot.configure']],
    'catalog' => $catalog, 'legacy' => $legacy, 'groups' => $groups,
    'registration_group' => 'ordinary', 'owner_group' => 'owner', 'tenant_group' => 'tenant',
    'bootstrap_permission' => 'access.bootstrap',
    'critical_permissions' => ['access.bootstrap', 'access.view', 'access.catalog.edit', 'access.group.update', 'access.group.disable', 'access.assign', 'access.delegate', 'access.revoke'],
    'bootstrap_email' => env('BOOTSTRAP_ADMIN_EMAIL'),
    'demo_supreme_email' => 'supreme@trohub.test',
    'personal_pages' => [
        'profile-security' => ['profile.update', 'profile.password'], 'conversations' => ['chat.view'], 'favorites' => ['favorite.manage'], 'compare-listings' => ['favorite.manage'],
        'connections' => ['contact.view'], 'roommate-discovery' => ['roommate.view'], 'rentals' => ['rental.view', 'contact.view'], 'rental-detail' => ['rental.view'],
        'landlord-upgrade' => ['workspace.create'], 'my-invoices' => ['invoice.view'], 'payments' => ['payment.view'], 'maintenance' => ['maintenance.view', 'maintenance.create'],
        'room-safety' => ['iot.view'], 'room-safety-history' => ['iot.log.view'], 'my-reports' => ['community.report.view'], 'notifications' => ['notification.view'],
    ],
    'continuity' => ['subscription.manage'],
];
