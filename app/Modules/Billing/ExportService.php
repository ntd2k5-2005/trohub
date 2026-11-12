<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;

final class ExportService
{
    public function download(User $user, ?Workspace $workspace, string $type)
    {
        abort_unless(in_array($type, ['invoice', 'payment'], true), 404);
        $permission = $type.'.export';
        $access = app(Authorization::class);
        abort_unless($workspace ? ($access->allowed($user, $permission, $workspace) || $access->roomIds($user, $workspace, $permission) !== []) : $access->personal($user, $permission), 403);
        $rentals = app(ScopedRepository::class)->rentals($user, $workspace, $permission);
        $invoices = Invoice::whereIn('rental_id', $rentals->select('id'))->when(! $workspace, fn ($q) => $q->whereNotNull('issued_at'));
        if ($type === 'invoice') {
            $rows = DB::table('invoices')->join('rentals', 'rentals.id', '=', 'invoices.rental_id')->join('rooms', 'rooms.id', '=', 'rentals.room_id')->whereIn('invoices.id', $invoices->select('id'))->orderBy('invoices.id')->select('invoices.id', 'invoices.period', 'rooms.name', 'invoices.total', 'invoices.due_at')->selectSub(DB::table('payments')->selectRaw('COALESCE(SUM(amount),0)')->whereColumn('invoice_id', 'invoices.id'), 'paid');
            $headers = ['Số hóa đơn', 'Kỳ', 'Phòng', 'Tổng tiền', 'Hạn thanh toán', 'Đã thanh toán'];
        } else {
            $rows = DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->join('rentals', 'rentals.id', '=', 'invoices.rental_id')->join('rooms', 'rooms.id', '=', 'rentals.room_id')->whereIn('invoices.id', $invoices->select('id'))->orderBy('payments.id')->select('payments.reference', 'invoices.period', 'rooms.name', 'payments.amount', 'payments.method', 'payments.paid_at');
            $headers = ['Mã giao dịch', 'Kỳ', 'Phòng', 'Số tiền', 'Phương thức', 'Ngày thanh toán'];
        }
        app(Journal::class)->record($user->id, $permission, $user, $workspace?->id, ['summary' => $type === 'invoice' ? 'Xuất hóa đơn' : 'Xuất lịch sử thanh toán']);

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            foreach ($rows->cursor() as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[\s]*[=+@-]/u', $v) ? "'".$v : $v, (array) $row), ',', '"', '');
            }
            fclose($out);
        }, ($type === 'invoice' ? 'hoa-don' : 'thanh-toan').'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
