<?php

namespace App\Modules\Workspace;

use App\Models\User;
use App\Modules\Notification\Events\InboxOpened;
use App\Modules\Notification\NotificationService;
use App\Modules\Workspace\Events\InvitationUpdated;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Auth\Events\Login;

final class InvitationNotifications
{
    public function __construct(private NotificationService $notifications) {}

    public function changed(InvitationUpdated $event): void
    {
        $invite = Invitation::findOrFail($event->invitationId);
        if ($user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($invite->email)])->first()) {
            $this->deliver($user, $invite, $event->attention);
        }
    }

    public function signedIn(Login $event): void
    {
        $this->sync($event->user);
    }

    public function inboxOpened(InboxOpened $event): void
    {
        $this->sync($event->user);
    }

    public function sync(User $user): void
    {
        foreach (Invitation::whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])->whereNull('accepted_at')->whereNull('cancelled_at')->whereNull('declined_at')->where('expires_at', '>', now())->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->get() as $invite) {
            $this->deliver($user, $invite, null);
        }
    }

    private function deliver(User $user, Invitation $invite, ?bool $attention): void
    {
        if ($invite->recipient_id !== $user->id && ($invite->recipient_id || ! $user->email_verified_at)) {
            return;
        }
        $workspace = Workspace::find($invite->workspace_id);
        if (! $workspace || ! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($workspace, $invite->created_at)) {
            return;
        }
        $title = $invite->purpose === 'tenant' ? 'Lời mời liên kết hồ sơ thuê' : 'Lời mời tham gia quản lý';
        $body = match (true) {
            (bool) $invite->accepted_at => 'Bạn đã chấp nhận lời mời tại '.$workspace->name.'.',
            (bool) $invite->declined_at => 'Bạn đã từ chối lời mời tại '.$workspace->name.'.',
            (bool) $invite->cancelled_at => 'Lời mời tại '.$workspace->name.' đã được hủy.',
            default => 'Bạn nhận được lời mời tại '.$workspace->name.'. Mở chi tiết để xem và phản hồi.',
        };
        $this->notifications->linked($user->id, 'invitation:'.$invite->id.':recipient:'.$user->id, $title, $body, route('invitation.show', ['invitation' => $invite->id], false), $attention, 'Xem lời mời', workspace: $invite->workspace_id);
    }
}
