<?php

namespace App\Modules\Identity;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SocialEmailCode extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Xác minh email · '.config('product.name'))
            ->greeting('Xác minh email của bạn')
            ->line('Mã xác minh: '.$this->code)
            ->line('Mã có hiệu lực trong '.config('social_login.email_code_minutes').' phút. Không chia sẻ mã này với người khác.')
            ->line('Nếu bạn không thực hiện đăng nhập, hãy bỏ qua email này.');
    }
}
