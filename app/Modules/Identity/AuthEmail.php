<?php

namespace App\Modules\Identity;

use Illuminate\Mail\Mailable;

class AuthEmail extends Mailable
{
    public function __construct(public array $contentData, public string $deliveryId) {}

    public function build(): static
    {
        return $this->subject($this->contentData['purpose'] === 'activation' ? 'Xác minh email · '.config('product.name') : 'Khôi phục mật khẩu · '.config('product.name'))
            ->view('emails.authentication', ['details' => $this->contentData])
            ->withSymfonyMessage(function ($message): void {
                $message->getHeaders()->addIdHeader('Message-ID', $this->deliveryId.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'));
            });
    }
}
