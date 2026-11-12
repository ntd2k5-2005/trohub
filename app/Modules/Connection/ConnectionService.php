<?php

namespace App\Modules\Connection;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Connection\Models\Connection;
use App\Modules\DataPlatform\Journal;
use App\Modules\Listing\ListingService;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConnectionService
{
    public function __construct(private ListingService $listings, private ScopedRepository $scope, private Journal $journal) {}

    public function create(User $user, int $listingId, string $message): Connection
    {
        abort_unless(app(Authorization::class)->personal($user, 'contact.create'), 403);
        abort_unless($user->status === 'active', 403);

        return DB::transaction(function () use ($user, $listingId, $message) {
            $listing = $this->listings->available()->lockForUpdate()->findOrFail($listingId);
            abort_if($listing->room->property->workspace->owner_id === $user->id, 422, 'Bạn không thể gửi liên hệ cho chính mình.');
            $c = Connection::firstOrCreate(['listing_id' => $listingId, 'user_id' => $user->id], ['message' => $message, 'cloud_epoch' => $listing->room->property->workspace->cloud_epoch]);
            app(ConversationRepository::class)->thread($c->id);
            if ($c->wasRecentlyCreated) {
                $this->journal->record($user->id, 'connection.created', $c, $listing->room->property->workspace_id);
                $this->journal->notify($listing->room->property->workspace->owner_id, 'Có khách quan tâm', 'Một khách vừa gửi yêu cầu liên hệ về phòng của bạn.', workspace: $listing->room->property->workspace_id);
            }

            return $c;
        });
    }

    public function transition(User $user, Connection $c, string $status, ?string $viewing = null): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->requireRoom($c->listing->room_id);
        abort_unless($user->status === 'active', 403);
        if ($c->user_id === $user->id) {
            abort_unless(app(Authorization::class)->personal($user, 'contact.schedule'), 403);
            abort_unless(in_array($status, ['cancelled', 'viewing_scheduled', 'viewing_confirmed'], true), 403);
        } else {
            $this->scope->managedRoom($user, $c->listing->room_id, 'connection.manage');
        }
        DB::transaction(function () use ($user, $c, $status, $viewing) {
            $c = Connection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $next = ['new' => ['contacted', 'viewing_scheduled', 'cancelled', 'rejected'], 'contacted' => ['viewing_scheduled', 'agreed', 'cancelled', 'rejected'], 'viewing_scheduled' => ['viewing_scheduled', 'viewing_confirmed', 'cancelled'], 'viewing_confirmed' => ['viewing_scheduled', 'viewed', 'cancelled'], 'viewed' => ['agreed', 'rejected', 'cancelled']];
            if (! in_array($status, $next[$c->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Bước xử lý không phù hợp trạng thái hiện tại.']);
            }
            if (in_array($status, ['viewing_scheduled', 'agreed']) && ! $this->listings->available()->whereKey($c->listing_id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Phòng hiện không còn nhận khách.']);
            }
            if ($status === 'viewing_scheduled' && (! $viewing || now()->parse($viewing)->isPast())) {
                throw ValidationException::withMessages(['viewing_at' => 'Cần lịch xem phòng trong tương lai.']);
            }
            if ($status === 'viewing_confirmed') {
                $proposer = $c->viewing_proposed_by ?? $c->listing->room->property->workspace->owner_id;
                $requesterProposed = $proposer === $c->user_id;
                abort_unless(($user->id === $c->user_id) !== $requesterProposed, 403, 'Lịch hẹn cần được bên còn lại xác nhận.');
            }
            $c->update(['status' => $status] + ($status === 'viewing_scheduled' ? ['viewing_at' => $viewing, 'viewing_proposed_by' => $user->id] : []));
            $this->journal->record($user->id, 'connection.updated', $c, $c->listing->room->property->workspace_id);
            $this->journal->notify($c->user_id === $user->id ? $c->listing->room->property->workspace->owner_id : $c->user_id, 'Liên hệ được cập nhật', 'Vui lòng xem lịch hẹn và trạng thái liên hệ mới nhất.', workspace: $c->listing->room->property->workspace_id);
        });
    }
}
