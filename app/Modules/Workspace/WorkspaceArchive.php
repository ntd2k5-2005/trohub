<?php

namespace App\Modules\Workspace;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\DataPlatform\Journal;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\DepositRefundDecision;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RentalRequest;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\WorkAssignment;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Archive never retires assets, settles money, or grants a future restoration. */
final class WorkspaceArchive
{
    public function archive(User $actor, Workspace $workspace, string $reason): void
    {
        abort_unless(trim($reason) !== '', 422);
        app(MembershipLifecycle::class)->requireSchema();
        app(StaffCapacity::class)->transaction($workspace, function ($w) use ($actor, $reason) {
            foreach (config('authorization.workspace_archive_permissions') as $code) app(Authorization::class)->authorize($actor, $code, $w);
            $rooms = $w->properties()->with('rooms')->get()->flatMap->rooms->pluck('id')->all();
            $rentals = Rental::whereIn('room_id', $rooms)->get();
            $openRental = $rentals->contains(fn ($r) => ! in_array($r->status, ['closed', 'cancelled'], true));
            $openInvoice = Invoice::whereIn('rental_id', $rentals->pluck('id'))->whereColumn('total', '>', 'paid')->exists();
            $openMoney = false;
            foreach ($rentals as $r) {
                $received = app(ReservationPayments::class)->received($r);
                $settled = max((int) DepositRefundDecision::where('rental_id', $r->id)->where('status', 'paid')->sum('amount_received'), (int) $r->deposits()->whereNotNull('settled_at')->sum('amount'));
                if ($received > $settled || app(ReservationPayments::class)->hasPending($r)) $openMoney = true;
            }
            $openWork = MaintenanceRequest::whereIn('room_id', $rooms)->where('status', '!=', 'closed')->exists()
                || WorkAssignment::where('workspace_id', $w->id)->whereIn('status', ['open', 'handover_pending'])->exists();
            $openRequest = RoomReservation::whereIn('room_id', $rooms)->whereNotNull('active_room_id')->exists()
                || RentalRequest::whereIn('room_id', $rooms)->whereIn('status', ['submitted', 'counter_offered'])->exists();
            abort_if($openRental || $openInvoice || $openMoney || $openWork || $openRequest, 409, 'Cần hoàn tất hồ sơ thuê, công nợ, cọc, yêu cầu và công việc còn mở trước khi lưu trữ khu quản lý.');
            $members = Membership::where('workspace_id', $w->id)->where('status', 'active')->lockForUpdate()->get();
            $w->update(['status' => 'archived', 'lifecycle' => 'archived', 'version' => $w->version + 1, 'lifecycle_reason' => $reason, 'cloud_epoch' => $w->cloud_epoch + 1, 'cloud_cutoff_at' => now()]);
            foreach ($members as $member) {
                $member->update(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'Khu quản lý đã được lưu trữ: '.$reason, 'version' => $member->version + 1]);
                app(Journal::class)->record($actor->id, 'membership.suspended', $member, $w->id, ['reason' => $member->suspension_reason]);
            }
            DB::table('listings')->whereIn('room_id', $rooms)->where('status', 'published')->update(['status' => 'paused']);
            DB::table('user_notifications')->where('workspace_id', $w->id)->whereNull('suppressed_at')->update(['suppressed_at' => now()]);
            app(Journal::class)->record($actor->id, 'workspace.archived', $w, $w->id, ['reason' => $reason, 'suspended_members' => $members->pluck('id')->all(), 'assets_retired' => false]);
        });
    }
}
