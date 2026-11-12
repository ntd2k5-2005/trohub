<?php

namespace App\Modules\Workspace;

use App\Models\User;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Events\InvitationUpdated;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvitationService
{
    public function __construct(private DelegationPolicy $delegation, private Journal $journal) {}

    public function received(User $user, int $id): Invitation
    {
        abort_unless($user->status === 'active', 403);
        $invite = Invitation::whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])->findOrFail($id);
        abort_unless($invite->recipient_id === $user->id || (! $invite->recipient_id && $user->email_verified_at), 403);
        app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($invite->workspace_id);

        return $invite;
    }

    public function state(Invitation $invite): string
    {
        return match (true) {
            (bool) $invite->accepted_at => 'accepted', (bool) $invite->declined_at => 'declined', (bool) $invite->cancelled_at => 'cancelled',
            $invite->expires_at->lte(now()) || ($invite->valid_until && $invite->valid_until->lte(now())) => 'expired',
            default => 'pending',
        };
    }

    public function decline(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $invite = Invitation::whereKey($this->received($user, $id)->id)->lockForUpdate()->firstOrFail();
            if ($this->state($invite) !== 'pending') {
                throw ValidationException::withMessages(['invitation' => 'Lời mời không còn chờ phản hồi. Vui lòng tải lại trang.']);
            }
            $invite->update(['declined_at' => now()]);
            $this->journal->record($user->id, 'invitation.declined', $invite, $invite->workspace_id, ['summary' => $user->email]);
            event(new InvitationUpdated($invite->id, false));
        });
    }

    public function declineToken(User $user, string $token): void
    {
        DB::transaction(function () use ($user, $token) {
            $id = $this->preview($user, $token)->id;
            $invite = Invitation::whereKey($id)->where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            if ($this->state($invite) !== 'pending') {
                throw ValidationException::withMessages(['invitation' => 'Lời mời không còn chờ phản hồi.']);
            }
            $invite->update(['recipient_id' => $user->id]);
            $this->decline($user, $id);
        });
    }

    public function change(User $user, Workspace $workspace, int $id, bool $renew): ?string
    {
        return DB::transaction(function () use ($user, $workspace, $id, $renew) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $invite = Invitation::where('workspace_id', $workspace->id)->where('purpose', 'staff')->lockForUpdate()->findOrFail($id);
            $codes = ! $renew && $workspace->owner_id === $user->id ? [] : array_unique([...($invite->permissions ?? []), ...AccessGroup::whereIn('id', $invite->access_group_ids ?? [])->get()->flatMap(fn ($g) => $g->permissions)->all()]);
            $this->delegation->workspace($user, $workspace, $codes, $invite->property_ids, $renew ? $invite->valid_until?->toDateTimeString() : now()->toDateTimeString(), $invite->room_ids);
            if ($invite->accepted_at || $invite->cancelled_at || $invite->declined_at) {
                throw ValidationException::withMessages(['invitation' => 'Lời mời đã được xử lý. Vui lòng tải lại danh sách.']);
            }
            $token = null;
            if ($renew) {
                if ($invite->valid_until?->isPast()) {
                    throw ValidationException::withMessages(['invitation' => 'Thời hạn truy cập đã hết. Hãy tạo lời mời mới với thời hạn phù hợp.']);
                }
                $target = User::where('email', $invite->email)->first();
                if ($target && Membership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->exists()) {
                    throw ValidationException::withMessages(['invitation' => 'Tài khoản đã tham gia khu quản lý.']);
                }
                if (Invitation::where('workspace_id', $workspace->id)->whereKeyNot($invite->id)->where('email', $invite->email)->where('purpose', 'staff')->whereNull('accepted_at')->whereNull('cancelled_at')->whereNull('declined_at')->where('expires_at', '>', now())->exists()) {
                    throw ValidationException::withMessages(['invitation' => 'Email này đang có một lời mời khác còn hiệu lực.']);
                }
                $token = Str::random(64);
                $invite->update(['token_hash' => hash('sha256', $token), 'expires_at' => $invite->valid_until ? now()->addDays(7)->min($invite->valid_until) : now()->addDays(7), 'inviter_id' => $user->id, 'recipient_id' => $target?->id]);
            } else {
                $invite->update(['cancelled_at' => now()]);
            }
            $this->journal->record($user->id, $renew ? 'invitation.renewed' : 'invitation.cancelled', $invite, $workspace->id, ['summary' => $invite->email]);

            event(new InvitationUpdated($invite->id));

            return $token;
        });
    }

    public function preview(User $user, string $token): Invitation
    {
        abort_unless($user->status === 'active', 403);
        $invite = Invitation::where('token_hash', hash('sha256', $token))->whereNull('cancelled_at')->whereNull('declined_at')->whereNull('accepted_at')->where('expires_at', '>', now())->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->firstOrFail();
        abort_unless(mb_strtolower($user->email) === $invite->email, 403, 'Hãy đăng nhập bằng email đã nhận lời mời.');
        app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($invite->workspace_id);

        return $invite;
    }

    public function permissionNames(Invitation $invite): array
    {
        return Permission::whereIn('code', $invite->permissions ?? [])->pluck('name')->all();
    }
}
