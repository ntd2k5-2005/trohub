<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\DataPlatform\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class BootstrapAdmin
{
    public function create(string $email, string $name, string $password): User
    {
        return DB::transaction(function () use ($email, $name, $password) {
            $group = AccessGroup::where('key', 'supreme')->where('active', true)->lockForUpdate()->firstOrFail();
            if (app(RootProtection::class)->permanentRoots()) {
                throw ValidationException::withMessages(['email' => 'Đã có quản trị viên cấp cao. Hãy sử dụng quy trình phân quyền trong ứng dụng.']);
            }
            if (! app()->environment(['local', 'testing']) && strlen($password) < 12) {
                throw ValidationException::withMessages(['password' => 'Mật khẩu quản trị cần ít nhất 12 ký tự.']);
            }
            if (User::where('email', mb_strtolower($email))->exists()) {
                throw ValidationException::withMessages(['email' => 'Email đã có tài khoản; không tự nâng quyền hoặc thay mật khẩu tài khoản hiện có.']);
            }
            $user = User::create(['name' => $name, 'email' => mb_strtolower($email), 'password' => Hash::make($password)]);
            GroupAssignment::create(['user_id' => $user->id, 'access_group_id' => $group->id, 'scope_key' => 'platform', 'origin' => 'bootstrap']);
            if (! in_array($user->id, app(RootProtection::class)->permanentRoots(), true)) {
                throw ValidationException::withMessages(['email' => 'Nhóm khởi tạo chưa có đủ quyền quản trị hiện hành.']);
            }
            app(Journal::class)->record($user->id, 'access.bootstrapped', $user, null, ['summary' => 'Khởi tạo quản trị viên cấp cao bằng quy trình máy chủ']);

            return $user;
        });
    }
}
