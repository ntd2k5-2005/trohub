<?php

namespace App\Modules\Rental;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Listing\ListingService;
use App\Modules\Notification\NotificationService;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RentalRequest;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RentalRequestService
{
    public function __construct(private Authorization $access, private Journal $journal, private RentalPolicy $policies, private ReservationPayments $payments) {}

    public function submit(User $user, int $listingId, array $input): RentalRequest
    {
        abort_unless($this->access->personal($user, 'contact.create'), 403);
        $data = Validator::make($input, ['starts_at' => 'required|date|after_or_equal:today', 'ends_at' => 'required|date|after:starts_at', 'people' => 'required|integer|min:1|max:100', 'message' => 'nullable|string|max:2000', 'accept_terms' => 'accepted', 'quote' => 'required|string|size:64'])->validate();
        $listing = app(ListingService::class)->available()->findOrFail($listingId);
        abort_if($listing->room->property->workspace->owner_id === $user->id, 403);

        return SqlTransactions::run(function () use ($user, $listing, $data, $input) {
            $room = Room::whereKey($listing->room_id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($this->policies->quote($room), (string) ($input['quote'] ?? ''))) {
                throw ValidationException::withMessages(['accept_terms' => 'Giá hoặc điều khoản đã thay đổi. Vui lòng tải lại tin và kiểm tra trước khi gửi.']);
            }
            abort_unless(app(ListingService::class)->available()->whereKey($listing->id)->exists(), 409, 'Phòng vừa thay đổi tình trạng, vui lòng tìm phòng khác.');
            if ($data['people'] > $room->capacity) {
                throw ValidationException::withMessages(['people' => 'Số người vượt quá sức chứa của phòng.']);
            }
            if ($room->available_from && $data['starts_at'] < $room->available_from) {
                throw ValidationException::withMessages(['starts_at' => 'Ngày bắt đầu cần từ ngày phòng sẵn sàng.']);
            }
            $key = $user->id.':'.$room->id;
            if ($existing = RentalRequest::where('active_key', $key)->first()) {
                return $existing;
            }
            $policy = $this->policies->forRoom($room);
            $request = RentalRequest::create(['user_id' => $user->id, 'room_id' => $room->id, 'listing_id' => $listing->id, 'workspace_id' => $room->property->workspace_id, 'active_key' => $key, 'message' => $data['message'] ?? null, 'terms_accepted_at' => now(), 'terms_snapshot' => collect($data)->only(['starts_at', 'ends_at', 'people'])->all() + ['rent' => $room->rent, 'deposit' => $room->deposit, 'deposit_terms' => $policy['deposit_terms'], 'policy' => $policy]]);
            $this->journal->record($user->id, 'rental_request.submitted', $request, $request->workspace_id);
            $this->notify($request, 'Yêu cầu thuê mới', 'Có yêu cầu thuê phòng cần xem xét.', true);

            return $request;
        });
    }

    public function decide(User $user, Workspace $workspace, RentalRequest $request, string $decision, int $version, array $input): void
    {
        abort_unless($request->workspace_id === $workspace->id, 403);
        $this->access->authorize($user, 'rental.create', $workspace, $request->room->property_id, $request->room_id);
        abort_unless(in_array($decision, ['accept', 'counter', 'reject'], true), 422);
        SqlTransactions::run(function () use ($user, $workspace, $request, $decision, $version, $input) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            app(\App\Modules\Workspace\WorkspaceLifecycle::class)->requireAdmission($workspace);
            $room = Room::whereKey($request->room_id)->lockForUpdate()->firstOrFail();
            $request = RentalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $beforeTerms = $request->terms_snapshot;
            abort_unless($request->workspace_id===$workspace->id&&$room->property->workspace_id===$workspace->id,409,'Phạm vi quản lý của phòng đã thay đổi. Vui lòng tải lại hồ sơ.');
            $this->access->authorize($user,'rental.create',$workspace->fresh(),$room->property_id,$room->id);
            if ($decision === 'accept' && $request->status === 'accepted' && $request->version === $version + 1) {
                return;
            }
            abort_unless($request->version === $version && in_array($request->status, ['submitted', 'counter_offered'], true), 409, 'Yêu cầu đã thay đổi. Vui lòng tải lại.');
            app(\App\Contracts\WorkAssignments::class)->assertCurrent($user,$workspace->id,'rental_negotiation',$request->id);
            if ($decision === 'reject') {
                $data = Validator::make($input, ['reason' => 'required|string|max:2000'])->validate();
                $request->update(['status' => 'rejected', 'active_key' => null, 'decision_reason' => $data['reason'], 'version' => $version + 1]);
            } elseif ($decision === 'counter') {
                $data = Validator::make($input, ['rent' => 'required|integer|min:1|max:1000000000', 'deposit' => 'required|integer|min:0|max:1000000000', 'deposit_terms' => 'required|string|max:5000', 'reason' => 'required|string|max:2000'])->validate();
                $terms = array_replace($request->terms_snapshot, collect($data)->only(['rent', 'deposit', 'deposit_terms'])->all());
                $request->update(['status' => 'counter_offered', 'terms_snapshot' => $terms, 'decision_reason' => $data['reason'], 'version' => $version + 1]);
                app(\App\Contracts\WorkAssignments::class)->claim($user,$workspace->id,'rental_negotiation',$request->id,'rental.create');
            } else {
                abort_unless($request->status === 'submitted', 409, 'Cần người thuê đồng ý điều kiện điều chỉnh trước khi giữ phòng.');
                abort_unless($room->status === 'vacant' && ! RoomReservation::where('active_room_id', $room->id)->exists() && ! $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists(), 409, 'Phòng đã được giữ hoặc đang có người thuê.');
                $terms = $request->terms_snapshot;
                $rental = app(RentalService::class)->create($user, ['room_id' => $room->id, 'tenant_id' => $request->user_id, 'starts_at' => $terms['starts_at'], 'ends_at' => $terms['ends_at'], 'rent' => $terms['rent'], 'deposit' => $terms['deposit'], 'terms' => $terms['deposit_terms']]);
                $policy = $terms['policy'];
                $reservation = RoomReservation::create(['room_id' => $room->id, 'active_room_id' => $room->id, 'rental_request_id' => $request->id, 'rental_id' => $rental->id, 'cloud_epoch' => $workspace->fresh()->cloud_epoch, 'hold_until' => now()->addHours((int) $policy['hold_hours']), 'policy_snapshot' => $policy, 'status' => $terms['deposit'] > 0 ? 'holding' : 'deposit_confirmed']);
                $room->update(['status' => 'reserved']);
                $room->listings()->where('status', 'published')->update(['status' => 'paused']);
                $request->update(['status' => 'accepted', 'rental_id' => $rental->id, 'version' => $version + 1]);
                if ($terms['deposit'] > 0 && (! $this->access->allowed($user, 'payment_request.issue', $workspace, $room->property_id, $room->id) || ! $this->payments->issue($user, $rental))) {
                    $this->notify($request, 'Cần thiết lập nhận tiền', 'Hồ sơ đã được giữ phòng. Hãy chọn tài khoản nhận tiền và phát hành yêu cầu cọc trước hạn giữ.', true);
                }
                $this->journal->record($user->id, 'reservation.held', $reservation, $workspace->id);
            }
            $this->journal->record($user->id, 'rental_request.'.$decision, $request, $workspace->id, ['before_terms' => $beforeTerms, 'after_terms' => $request->terms_snapshot]);
            if($decision!=='counter')app(\App\Contracts\WorkAssignments::class)->complete($workspace->id,'rental_negotiation',$request->id);
            $this->access->authorize($user,'rental.create',$workspace->fresh(),$room->property_id,$room->id);
            $this->notify($request, 'Yêu cầu thuê có cập nhật', 'Kiểm tra điều kiện thuê, thời hạn giữ phòng và bước tiếp theo.');
        });
    }

    public function acceptOffer(User $user, RentalRequest $request, int $version): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($request->workspace_id);
        abort_unless($request->user_id === $user->id && $this->access->personal($user, 'contact.schedule'), 403);
        SqlTransactions::run(function () use ($user, $request, $version) {
            $request = RentalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->version === $version && $request->status === 'counter_offered', 409);
            $request->update(['status' => 'submitted', 'terms_accepted_at' => now(), 'version' => $version + 1]);
            $this->journal->record($user->id, 'rental_request.offer_accepted', $request, $request->workspace_id);
            $this->notify($request, 'Người thuê đồng ý điều kiện', 'Bạn có thể xem xét và chấp thuận giữ phòng.', true);
        });
    }

    public function reserveManual(User $user, Workspace $workspace, Rental $rental): void
    {
        abort_unless($rental->room->property->workspace_id === $workspace->id, 403);
        $this->access->authorize($user, 'rental.create', $workspace, $rental->room->property_id, $rental->room_id);
        SqlTransactions::run(function () use ($user, $workspace, $rental) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $room = Room::whereKey($rental->room_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            if (RoomReservation::where('rental_id', $rental->id)->exists()) {
                return;
            }
            abort_unless($rental->status === 'draft' && $rental->tenant_id && $rental->confirmed_at, 409, 'Cần người thuê liên kết tài khoản và xác nhận điều khoản trước khi giữ phòng.');
            $legacyFunded = $rental->deposits()->whereNull('settled_at')->exists();
            abort_unless(($room->status === 'vacant' || $room->status === 'reserved' && $legacyFunded) && ! RoomReservation::where('active_room_id', $room->id)->exists() && ! $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists(), 409, 'Phòng đã được giữ hoặc đang có người thuê.');
            $key = $rental->tenant_id.':'.$room->id;
            abort_if(RentalRequest::where('active_key', $key)->exists(), 409, 'Người thuê đã có yêu cầu cho phòng này. Hãy xử lý yêu cầu hiện có.');
            abort_unless(app(RentalService::class)->hasPolicySnapshot($rental), 409, 'Cần người thuê xác nhận lại chính sách giữ phòng trước khi tiếp tục.');
            $policy = app(RentalService::class)->reviewPolicy($rental);
            $terms = ['starts_at' => $rental->starts_at, 'ends_at' => $rental->ends_at, 'people' => 1, 'rent' => $rental->rent, 'deposit' => $rental->deposit, 'deposit_terms' => $rental->terms, 'policy' => $policy];
            $request = RentalRequest::create(['user_id' => $rental->tenant_id, 'room_id' => $room->id, 'workspace_id' => $workspace->id, 'rental_id' => $rental->id, 'active_key' => $key, 'status' => 'accepted', 'terms_snapshot' => $terms, 'terms_accepted_at' => $rental->confirmed_at]);
            $funded = $this->payments->received($rental) >= $rental->deposit;
            $reservation = RoomReservation::create(['room_id' => $room->id, 'active_room_id' => $room->id, 'rental_request_id' => $request->id, 'rental_id' => $rental->id, 'cloud_epoch' => $workspace->fresh()->cloud_epoch, 'hold_until' => now()->addHours((int) $policy['hold_hours']), 'policy_snapshot' => $policy, 'status' => $funded ? 'deposit_confirmed' : 'holding']);
            $room->update(['status' => 'reserved']);
            $room->listings()->where('status', 'published')->update(['status' => 'paused']);
            // Creating a hold does not grant payment permissions to the creator.
            if (! $funded && $this->access->allowed($user, 'payment_request.issue', $workspace, $room->property_id, $room->id)) {
                $this->payments->issue($user, $rental);
            }
            $this->journal->record($user->id, 'reservation.held', $reservation, $workspace->id, ['source' => 'confirmed_rental']);
            $this->notify($request, 'Đã giữ phòng', 'Kiểm tra thời hạn giữ phòng và bước thanh toán cọc trong hồ sơ thuê.');
        });
    }

    public function cancel(User $user, RentalRequest $request, string $reason): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($request->workspace_id);
        abort_unless($user->id === $request->user_id && $this->access->personal($user, 'contact.schedule'), 403);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();
        SqlTransactions::run(function () use ($user, $request, $reason) {
            Room::whereKey($request->room_id)->lockForUpdate()->firstOrFail();
            $request = RentalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status === 'cancelled') {
                return;
            }
            abort_unless(in_array($request->status, ['submitted', 'counter_offered', 'accepted'], true) && (! $request->rental || $request->rental->status === 'draft'), 409);
            $request->update(['status' => 'cancelled', 'active_key' => null, 'decision_reason' => $reason, 'version' => $request->version + 1]);
            app(\App\Contracts\WorkAssignments::class)->complete($request->workspace_id,'rental_negotiation',$request->id);
            if ($reservation = $request->reservation) {
                $received = $this->payments->received($reservation->rental);
                $pending = $this->payments->hasPending($reservation->rental);
                if ($received > 0 || $pending) {
                    $reservation->update(['status' => 'review_pending', 'reason' => $reason, 'exception_until' => now()->addHours((int) $reservation->policy_snapshot['exception_hours'])]);
                } else {
                    $this->release($reservation, 'cancelled', $reason);
                }
            }
            $this->journal->record($user->id, 'rental_request.cancelled', $request, $request->workspace_id);
            $this->notify($request, 'Người thuê hủy yêu cầu', 'Kiểm tra hồ sơ và các khoản cọc cần đối soát hoặc hoàn trả.', true);
        });
    }

    public function reservationAction(User $user, Workspace $workspace, RoomReservation $reservation, string $action, int $version, array $input): void
    {
        abort_unless($reservation->room->property->workspace_id === $workspace->id, 403);
        $this->access->authorize($user, match ($action) {
            'extend' => 'reservation.extend', 'waive' => 'deposit.waive', default => 'reservation.resolve'
        }, $workspace, $reservation->room->property_id, $reservation->room_id);
        SqlTransactions::run(function () use ($user, $workspace, $reservation, $action, $version, $input) {
            Room::whereKey($reservation->room_id)->lockForUpdate()->firstOrFail();
            $reservation = RoomReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->version === $version && $reservation->active_room_id, 409, 'Lượt giữ phòng đã thay đổi. Vui lòng tải lại.');
            $reason = Validator::make($input, ['reason' => 'required|string|max:2000'])->validate()['reason'];
            if ($action === 'extend') {
                $hours = (int) Validator::make($input, ['hours' => 'required|integer|min:1|max:'.$reservation->policy_snapshot['extension_hours']])->validate()['hours'];
                abort_unless(in_array($reservation->status, ['holding', 'deposit_confirmed'], true) && $reservation->hold_until->isFuture() && $reservation->extensions < $reservation->policy_snapshot['max_extensions'], 409, 'Không còn lượt gia hạn hoặc thời hạn giữ phòng đã kết thúc.');
                $reservation->update(['hold_until' => $reservation->hold_until->copy()->addHours($hours), 'extensions' => $reservation->extensions + 1, 'version' => $version + 1, 'reason' => $reason]);
                DB::table('payment_requests')->where('rental_id', $reservation->rental_id)->where('kind', 'deposit')->where('status', '!=', 'verified')->update(['expires_at' => $reservation->hold_until]);
            } elseif ($action === 'release') {
                $this->release($reservation, 'released', $reason);
            } elseif ($action === 'retain') {
                abort_unless(in_array($reservation->status, ['manual_review', 'review_pending'], true) && $reservation->request->status === 'accepted', 409);
                $until = Validator::make($input, ['until' => 'required|date|after:now|before_or_equal:'.now()->addHours((int) $reservation->policy_snapshot['exception_hours'])->toDateTimeString()])->validate()['until'];
                $received = $this->payments->received($reservation->rental);
                abort_unless($received <= $reservation->rental->deposit, 409, 'Cần xử lý riêng phần tiền nhận dư trước khi tiếp tục giữ phòng.');
                $reservation->update(['status' => $received >= $reservation->rental->deposit ? 'deposit_confirmed' : 'holding', 'hold_until' => $until, 'exception_until' => null, 'version' => $version + 1, 'reason' => $reason]);
                if ($reservation->rental->deposit > 0 && $received === $reservation->rental->deposit && ! $reservation->rental->deposits()->exists()) {
                    app(RentalService::class)->allocateVerifiedDeposit($user, $reservation->rental);
                }
                DB::table('payment_requests')->where('rental_id', $reservation->rental_id)->where('kind', 'deposit')->where('status', '!=', 'verified')->update(['expires_at' => $until]);
            } elseif ($action === 'waive') {
                abort_unless(in_array($reservation->status, ['holding', 'deposit_confirmed'], true) && $reservation->hold_until->isFuture(), 409);
                app(RentalService::class)->act($user, $reservation->rental, 'waive', ['reason' => $reason]);
                $terms = $reservation->request->terms_snapshot;
                $terms['deposit'] = 0;
                $reservation->request->update(['terms_snapshot' => $terms, 'version' => $reservation->request->version + 1]);
                $reservation->update(['status' => 'deposit_confirmed', 'version' => $version + 1, 'reason' => $reason]);
                DB::table('payment_requests')->where('rental_id', $reservation->rental_id)->where('kind', 'deposit')->update(['status' => 'cancelled']);
            } else {
                abort(422);
            }
            $this->journal->record($user->id, 'reservation.'.$action, $reservation, $workspace->id, ['reason' => $reason]);
            $this->notify($reservation->request, 'Cập nhật giữ phòng', 'Thời hạn hoặc tình trạng giữ phòng vừa thay đổi. Kiểm tra hồ sơ thuê.');
        });
    }

    public function expire(): int
    {
        $count = 0;
        RoomReservation::whereNotNull('active_room_id')->whereIn('status', ['holding', 'deposit_confirmed', 'review_pending'])->where('hold_until', '<=', now())->chunkById(100, function ($items) use (&$count) {
            foreach ($items as $candidate) {
                if (! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($candidate->room->property->workspace, $candidate->created_at, $candidate->cloud_epoch)) continue;
                SqlTransactions::run(function () use ($candidate, &$count) {
                    Room::whereKey($candidate->room_id)->lockForUpdate()->firstOrFail();
                    $r = RoomReservation::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    if (! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($r->room->property->workspace, $r->created_at, $r->cloud_epoch)) return;
                    if (! $r->active_room_id || $r->hold_until->isFuture()) {
                        return;
                    }
                    if ($r->status === 'review_pending') {
                        if ($r->exception_until?->isFuture()) {
                            return;
                        }
                        $r->update(['status' => 'manual_review', 'version' => $r->version + 1]);
                    } elseif ($this->payments->received($r->rental) > 0 || $this->payments->hasPending($r->rental)) {
                        $r->update(['status' => 'review_pending', 'exception_until' => now()->addHours((int) $r->policy_snapshot['exception_hours']), 'version' => $r->version + 1]);
                    } else {
                        $this->release($r, 'expired', 'Hết thời hạn giữ phòng, chưa có khoản nhận hoặc chứng từ chờ đối soát.');
                    }
                    $this->journal->record(null, 'reservation.'.$r->status, $r, $r->room->property->workspace_id);
                    $this->notify($r->request, 'Cập nhật thời hạn giữ phòng', 'Hãy kiểm tra tình trạng giữ phòng và các khoản cọc cần xử lý.');
                    $this->notify($r->request, 'Cần kiểm tra giữ phòng', 'Có hồ sơ thuê hết hạn hoặc cần quyết định xử lý.', true);
                    $count++;
                });
            }
        });

        return $count;
    }

    private function release(RoomReservation $reservation, string $status, string $reason): void
    {
        $reservation->update(['status' => $status, 'active_room_id' => null, 'reason' => $reason, 'version' => $reservation->version + 1]);
        if (! $reservation->room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists()) {
            $reservation->room->update(['status' => 'vacant']);
        }
        $reservation->rental->update(['status' => 'cancelled', 'closed_at' => now()]);
        $reservation->request->update(['active_key' => null, 'status' => $status === 'expired' ? 'expired' : 'cancelled']);
        // Preserve payments, proofs and old QR snapshots. Do not republish listings or decide refunds.
    }

    private function notify(RentalRequest $request, string $title, string $body, bool $owner = false): void
    {
        $user = $owner ? $request->room->property->workspace->owner_id : $request->user_id;
        $query = $owner ? ['context' => 'workspace', 'workspace_id' => $request->workspace_id] : ['context' => 'personal'];
        app(NotificationService::class)->linked($user, 'rental-request:'.$request->id.':'.$request->version.':'.($owner ? 'owner' : 'tenant').':'.hash('sha256', $title), $title, $body, route('ui.show', ['slug' => 'rentals'] + $query), workspace: $request->workspace_id);
    }
}
