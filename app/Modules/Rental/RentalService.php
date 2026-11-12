<?php

namespace App\Modules\Rental;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\Connection\Models\Connection;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Contracts\CashDepositRecorder;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Contracts\SettlementLedger;
use App\Modules\Rental\Models\ContractVersion;
use App\Modules\Rental\Models\Deposit;
use App\Modules\Rental\Models\Occupant;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\ResidentProfile;
use App\Modules\Rental\Models\RoomReservation;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RentalService
{
    public function __construct(private ScopedRepository $scope, private Journal $journal) {}

    private function ensure(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['rental' => $message]);
        }
    }

    public function create(User $user, array $data): Rental
    {
        $room = $this->scope->managedRoom($user, $data['room_id'], 'rental.create');

        return app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, 'rental.create', function () use ($user, $data, $room) {
            app(\App\Modules\Workspace\WorkspaceLifecycle::class)->requireAdmission($room->property->workspace);
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $this->ensure($room->status === 'vacant' && ! $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists(), 'Phòng hiện không thể cho thuê.');
            if (! empty($data['connection_id'])) {
                $connection = Connection::findOrFail($data['connection_id']);
                $candidateTenant = $data['tenant_id'] ?? User::where('email', $data['tenant_email'] ?? '')->value('id');
                $this->ensure($connection->status === 'agreed' && $connection->listing->room_id === $room->id && $connection->user_id === (int) $candidateTenant, 'Khách chưa có thỏa thuận hợp lệ cho phòng này.');
            }
            if (isset($data['tenant_email'])) {
                $tenant = User::where('email', $data['tenant_email'])->first();
                $resident = ResidentProfile::firstOrCreate(['workspace_id' => $room->property->workspace_id, 'email' => $data['tenant_email']], ['name' => $data['tenant_name'], 'user_id' => $tenant?->id]);
                $data['tenant_id'] = $tenant?->id;
                $data['resident_profile_id'] = $resident->id;
                unset($data['tenant_email'],$data['tenant_name']);
            }
            $rental = Rental::create(array_replace($data, ['cloud_epoch' => $room->property->workspace->cloud_epoch]));
            $this->version($user, $rental);
            $this->journal->record($user->id, 'rental.created', $rental, $room->property->workspace_id);
            if ($rental->tenant_id) {
                $this->journal->notify($rental->tenant_id, 'Hồ sơ thuê mới', 'Bạn có hồ sơ thuê mới cần kiểm tra và xác nhận.', workspace: $room->property->workspace_id);
            }

            if ($rental->tenant_id) {
                app(GroupAssignments::class)->tenant(User::findOrFail($rental->tenant_id));
            }

            return $rental;
        });
    }

    private function version(User $user, Rental $rental): void
    {
        ContractVersion::create(['rental_id' => $rental->id, 'version' => $rental->versions()->count() + 1, 'actor_id' => $user->id,
            'terms' => $rental->only(['starts_at', 'ends_at', 'rent', 'deposit', 'terms', 'status']) + ['rental_policy' => $this->reviewPolicy($rental)]]);
    }

    public function reviewPolicy(Rental $rental): array
    {
        return RoomReservation::where('rental_id', $rental->id)->first()?->policy_snapshot
            ?? ($rental->versions()->orderByDesc('version')->first()?->terms['rental_policy'] ?? app(RentalPolicy::class)->forRoom($rental->room));
    }

    public function hasPolicySnapshot(Rental $rental): bool
    {
        return RoomReservation::where('rental_id', $rental->id)->exists() || isset($rental->versions()->orderByDesc('version')->first()?->terms['rental_policy']);
    }

    public function reviewQuote(Rental $rental): string
    {
        return hash_hmac('sha256', json_encode([$rental->id, $rental->only(['starts_at', 'ends_at', 'rent', 'deposit', 'terms']), $this->reviewPolicy($rental)]), config('app.key'));
    }

    public function act(User $user, Rental $rental, string $action, array $data = []): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->requireRoom($rental->room_id);
        if ($action === 'deposit') {
            app(CashDepositRecorder::class)->record($user, $rental, $data);

            return;
        }
        $personal = in_array($action, ['confirm', 'request_move_out'], true) && $rental->tenant_id === $user->id;
        if ($personal) {
            abort_unless(app(Authorization::class)->personal($user, $action === 'confirm' ? 'tenant.confirm' : 'tenant.move_out'), 403);
        }
        if (! $personal) {
            $permission = match ($action) {
                'confirm' => 'tenant.confirm','activate' => 'rental.activate','deposit' => 'deposit.record','renew' => 'rental.renew',
                'request_move_out' => 'rental.move_out','settle' => 'deposit.settle','close' => 'rental.close','occupant' => 'rental.occupant','cancel' => 'rental.cancel','waive' => 'deposit.waive',
                default => null,
            };
            abort_unless($permission, 403);
            $this->scope->managedRoom($user, $rental->room_id, $permission);
        }
        $this->transition($user, $rental, $action, $data);
    }

    public function receiveVerifiedDeposit(User $user, Rental $rental): void
    {
        $this->scope->managedRoom($user, $rental->room_id, 'payment.verify');
        $this->ensure(app(ReservationPayments::class)->received($rental) === $rental->deposit, 'Số cọc đã đối soát chưa đủ.');
        $this->transition($user, $rental, 'deposit', []);
    }

    public function receiveCashDeposit(User $user, Rental $rental): void
    {
        $this->scope->managedRoom($user, $rental->room_id, 'deposit.record');
        $this->ensure(app(ReservationPayments::class)->received($rental) === $rental->deposit, 'Số cọc thực nhận chưa đủ.');
        $this->transition($user, $rental, 'deposit', []);
    }

    public function activationBlocker(Rental $rental): ?string
    {
        if ($rental->status !== 'draft' || ! $rental->tenant_id || ! $rental->confirmed_at) {
            return 'Cần người thuê xác nhận hồ sơ trước khi kích hoạt.';
        }
        if ($rental->starts_at > today()->toDateString() || $rental->ends_at < today()->toDateString()) {
            return 'Chưa đến ngày có hiệu lực hoặc hồ sơ đã hết thời hạn.';
        }
        if ($rental->deposit > 0 && $rental->deposits()->whereNull('settled_at')->sum('amount') < $rental->deposit) {
            return 'Cần đối soát đủ tiền cọc trước khi kích hoạt.';
        }
        $hold = RoomReservation::where('rental_id', $rental->id)->first();
        if (! $hold) {
            return 'Cần xác nhận giữ phòng có thời hạn trước khi kích hoạt thuê.';
        }
        if ($hold && (! $hold->active_room_id || ! in_array($hold->status, ['holding', 'deposit_confirmed'], true) || ! $hold->hold_until->isFuture() || $hold->request->status !== 'accepted')) {
            return 'Giữ phòng đã hết hạn hoặc đang chờ xử lý.';
        }
        $room = $rental->room;
        $eligible = $room->status === 'vacant' || $room->status === 'reserved' && ($hold?->active_room_id || $rental->deposits()->whereNull('settled_at')->exists());
        if (! $eligible || $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists()) {
            return 'Phòng đã có quan hệ thuê khác hoặc chưa sẵn sàng.';
        }

        return null;
    }

    public function allocateVerifiedDeposit(User $user, Rental $rental): void
    {
        $this->scope->managedRoom($user, $rental->room_id, 'reservation.resolve');
        $this->ensure(app(ReservationPayments::class)->received($rental) === $rental->deposit, 'Số tiền đã đối soát chưa khớp tiền cọc.');
        $this->transition($user, $rental, 'deposit', []);
    }

    private function transition(User $user, Rental $rental, string $action, array $data): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->transaction($rental->room->property->workspace_id, function () use ($user, $rental, $action, $data) {
            // All rental transitions lock the room first to serialize competing activations.
            $room = Room::whereKey($rental->room_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            switch ($action) {
                case 'waive':
                    $this->ensure($rental->status === 'draft' && app(ReservationPayments::class)->received($rental) === 0 && ! app(ReservationPayments::class)->hasPending($rental), 'Chỉ miễn cọc trước khi nhận tiền hoặc có chứng từ cần đối soát.');
                    $rental->update(['deposit' => 0, 'confirmed_at' => null]);
                    $this->version($user, $rental);
                    break;
                case 'confirm':
                    $this->ensure($user->id === $rental->tenant_id && $rental->status === 'draft', 'Hồ sơ không chờ xác nhận.');
                    $this->ensure(! isset($data['terms_quote']) && $this->hasPolicySnapshot($rental) || isset($data['terms_quote']) && is_string($data['terms_quote']) && hash_equals($this->reviewQuote($rental), $data['terms_quote']), 'Điều khoản đã thay đổi. Vui lòng tải lại và xác nhận thông tin mới.');
                    if (! $this->hasPolicySnapshot($rental)) {
                        $this->version($user, $rental);
                    }
                    $rental->update(['confirmed_at' => now()]);
                    break;
                case 'deposit':
                    $this->ensure(in_array($rental->status, ['draft', 'active'], true), 'Không thể nhận cọc ở trạng thái này.');
                    $this->ensure(! $rental->deposits()->exists(), 'Tiền cọc đã được ghi nhận.');
                    $this->ensure($rental->deposit > 0, 'Hợp đồng này không yêu cầu tiền cọc.');
                    if ($rental->status === 'draft') {
                        $heldHere = RoomReservation::where('rental_id', $rental->id)->whereNotNull('active_room_id')->exists();
                        $this->ensure($room->status === 'vacant' || $room->status === 'reserved' && $heldHere, 'Phòng đã được giữ hoặc cho thuê.');
                        $room->update(['status' => 'reserved']);
                        $room->listings()->where('status', 'published')->update(['status' => 'paused']);
                    }
                    Deposit::create(['rental_id' => $rental->id, 'amount' => $rental->deposit, 'received_at' => now()]);
                    break;
                case 'activate':
                    $reservation = RoomReservation::where('rental_id', $rental->id)->lockForUpdate()->first();
                    $blocker = $this->activationBlocker($rental);
                    $this->ensure($blocker === null, $blocker ?? '');
                    $rental->update(['status' => 'active', 'signed_at' => now(), 'residency_started_at' => now()]);
                    if ($reservation) {
                        $reservation->update(['status' => 'completed', 'active_room_id' => null, 'version' => $reservation->version + 1]);
                        $reservation->request->update(['active_key' => null]);
                    }
                    $room->update(['status' => 'occupied']);
                    Occupant::firstOrCreate(['rental_id' => $rental->id, 'user_id' => $rental->tenant_id], ['relationship' => 'primary', 'starts_at' => now()]);
                    $room->listings()->where('status', 'published')->update(['status' => 'paused']);
                    $this->version($user, $rental);
                    break;
                case 'occupant':
                    $this->ensure($rental->status === 'active' && $rental->occupants()->whereNull('ends_at')->count() < $room->capacity, 'Phòng không còn chỗ hoặc chưa có hợp đồng hiệu lực.');
                    $residentId = $data['user_id'] ?? User::where('email', $data['email'] ?? '')->value('id');
                    $this->ensure($residentId !== null, 'Người cư trú cần đăng ký tài khoản bằng email trước khi liên kết.');
                    Occupant::firstOrCreate(['rental_id' => $rental->id, 'user_id' => $residentId], ['relationship' => 'resident', 'starts_at' => now()]);
                    app(GroupAssignments::class)->tenant(User::findOrFail($residentId));
                    break;
                case 'cancel':
                    $this->ensure(! RoomReservation::where('rental_id', $rental->id)->exists(), 'Hãy xử lý hoặc hủy giữ phòng trong yêu cầu thuê; tiền cọc cần được đối soát riêng.');
                    $this->ensure($rental->status === 'draft', 'Chỉ hủy hồ sơ chưa kích hoạt.');
                    $deposit = $rental->deposits()->first();
                    if ($deposit) {
                        $this->ensure(! $deposit->settled_at, 'Tiền cọc đã quyết toán.');
                        // Cancellation preserves funds; actual refunds follow an agreed, audited decision.
                        $room->update(['status' => 'vacant']);
                    }
                    $rental->update(['status' => 'cancelled', 'closed_at' => now()]);
                    $this->version($user, $rental);
                    break;
                case 'renew':
                    $this->ensure($rental->status === 'active' && $data['ends_at'] > $rental->ends_at, 'Ngày gia hạn phải sau ngày kết thúc hiện tại.');
                    $rental->update(['ends_at' => $data['ends_at']]);
                    $this->version($user, $rental);
                    break;
                case 'request_move_out':
                    $this->ensure($rental->status === 'active', 'Chỉ hợp đồng đang thuê mới được yêu cầu trả phòng.');
                    $rental->update(['status' => 'move_out_pending', 'move_out_at' => $data['move_out_at']]);
                    $room->update(['status' => 'move_out_pending']);
                    break;
                case 'settle':
                    $this->ensure($rental->status === 'move_out_pending', 'Chưa có yêu cầu trả phòng.');
                    $deposit = Deposit::where('rental_id', $rental->id)->lockForUpdate()->first();
                    $this->ensure($deposit !== null && ! $deposit->settled_at, 'Cần có khoản cọc chưa quyết toán.');
                    $this->ensure($data['deduction'] <= $deposit->amount && ($data['deduction'] == 0 || ! empty($data['reason'])), 'Khấu trừ không hợp lệ hoặc thiếu lý do.');
                    $deposit->update(['deduction' => $data['deduction'], 'reason' => $data['reason'] ?? null, 'settled_at' => now()]);
                    break;
                case 'close':
                    $this->ensure($rental->status === 'move_out_pending', 'Hồ sơ chưa chờ trả phòng.');
                    $this->ensure($rental->move_out_at <= today()->toDateString(), 'Chưa đến ngày bàn giao.');
                    $this->ensure($rental->deposit == 0 || $rental->deposits()->whereNotNull('settled_at')->exists(), 'Cần quyết toán cọc trước.');
                    $invoices = $rental->invoices()->get();
                    $this->ensure($invoices->where('period', substr($rental->move_out_at, 0, 7))->whereNotNull('issued_at')->isNotEmpty(), 'Cần phát hành hóa đơn cuối kỳ.');
                    $this->ensure($invoices->every(fn ($i) => $i->issued_at && $i->outstanding === 0), 'Cần chốt đầy đủ hóa đơn và công nợ trước khi bàn giao.');
                    app(SettlementLedger::class)->assertFinalReadings($room->id, $room->property_id, substr($rental->move_out_at, 0, 7));
                    $this->ensure(! empty($data['handover_complete']), 'Cần xác nhận đã kiểm tra và bàn giao phòng.');
                    $rental->update(['status' => 'closed', 'closed_at' => now(), 'handover_complete' => true]);
                    $rental->occupants()->whereNull('ends_at')->update(['ends_at' => now()]);
                    $room->update(['status' => 'vacant']);
                    $this->version($user, $rental);
                    break;
                default: abort(404);
            }
            $this->journal->record($user->id, 'rental.'.$action, $rental, $room->property->workspace_id, $data);
            if ($rental->tenant_id) {
                $this->journal->notify($rental->tenant_id, 'Hồ sơ thuê được cập nhật', 'Thông tin thuê của bạn vừa được cập nhật. Vui lòng kiểm tra hồ sơ thuê.', workspace: $room->property->workspace_id);
            }
        });
    }
}
