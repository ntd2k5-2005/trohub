<?php

namespace App\Console\Commands;

use App\Modules\AccessControl\BootstrapAdmin;
use Illuminate\Console\Command;

final class BootstrapAdminCommand extends Command
{
    protected $signature = 'access:bootstrap {--email=} {--name=}';

    protected $description = 'Khởi tạo quản trị viên cấp cao đầu tiên bằng mật khẩu được nhập kín.';

    public function handle(): int
    {
        $email = $this->option('email') ?? config('authorization.bootstrap_email');
        $name = $this->option('name') ?? 'Quản trị viên';
        $password = getenv('TROHUB_BOOTSTRAP_PASSWORD') ?: ($this->input->isInteractive() ? $this->secret('Mật khẩu quản trị mới (ít nhất 12 ký tự)') : null);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $password || strlen($password) < 12) {
            $this->error('Cần email hợp lệ và mật khẩu riêng ít nhất 12 ký tự.');

            return self::FAILURE;
        }
        $u = app(BootstrapAdmin::class)->create($email, $name, $password);
        $this->info('Đã khởi tạo quản trị viên: '.$u->email);

        return self::SUCCESS;
    }
}
