<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::updated(function ($user) {
            if ($user->wasChanged('access_group_id')) app(\App\Modules\AccessControl\GrantGraph::class)->reconcile();
        });
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'password_login_enabled' => 'boolean', 'email_activation_required' => 'boolean'];
    }

    public function needsEmailActivation(): bool
    {
        return (bool) $this->email_activation_required && !$this->email_verified_at;
    }

    public function sendPasswordResetNotification($token): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('auth_mail_deliveries')) {
            app(\App\Modules\Identity\AuthMail::class)->passwordReset($this, $token);
        } else {
            parent::sendPasswordResetNotification($token);
        }
    }
}
