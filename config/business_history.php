<?php
return [
    'dataset'=>'TROHUB_BUSINESS_HISTORY_V1','version'=>1,'random_seed'=>261110,'reference_date'=>'2026-11-10',
    'existing_landlords'=>22,'onboarding_accounts'=>8,'pending_quotes'=>12,'closed_quotes'=>6,
    'plans'=>['trial'=>['label'=>'Dùng thử','price'=>0,'duration_count'=>14,'duration_unit'=>'days'], 'monthly'=>['label'=>'Theo tháng','price'=>49000,'duration_count'=>1,'duration_unit'=>'months'], 'yearly'=>['label'=>'Theo năm','price'=>490000,'duration_count'=>1,'duration_unit'=>'years']],
    // Historical synthetic entitlement only. No operational plan is published.
    'limits'=>['workspaces'=>'unlimited','properties'=>'unlimited','rooms'=>'unlimited','staff'=>'unlimited'],
    'display_names'=>[
        'platform@trohub.test'=>['Quản trị nền tảng','Trần Minh Quân'],'system@trohub.test'=>['Quản trị hệ thống','Nguyễn Hoàng Nam'],
        'other@trohub.test'=>['Chủ trọ khác','Lê Thanh Tùng'],'maintenance@trohub.test'=>['Nhân viên bảo trì','Phạm Quốc Huy'],
        'supreme@trohub.test'=>['Quản trị cấp cao','Ngô Tấn Đạt'],'user@trohub.test'=>['Người dùng trình diễn','Lê Ngọc Mai'],
        'multi@trohub.test'=>['Nhân viên kiêm kế toán','Trần Thùy Dương'],'grant@trohub.test'=>['Nhân viên có quyền bổ sung','Nguyễn Đức Anh'],
        'roomstaff@trohub.test'=>['Kỹ thuật viên theo phòng','Võ Minh Khang'],'expired@trohub.test'=>['Nhân viên hết quyền','Phạm Bảo Khánh'],
    ],
];
