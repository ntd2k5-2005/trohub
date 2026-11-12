<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReceivingAccountService
{
    public function __construct(private Authorization $access, private Journal $journal, private BankCatalog $catalog) {}

    public function save(User $user, Workspace $workspace, array $input, ?int $id = null): ReceivingAccount
    {
        $data = Validator::make($input, [
            'property_id' => 'nullable|integer|min:1', 'bank_bin' => ['required', 'regex:/^\d{6}$/'],
            'account_no' => ['required', 'string', config('vietqr.driver') === 'api' ? 'regex:/^\d{6,19}$/' : 'regex:/^[A-Za-z0-9]{1,19}$/'],
            'account_holder_name' => 'required|string|min:2|max:100', 'label' => 'required|string|max:100',
            'version' => $id ? 'required|integer|min:1' : 'nullable|integer', 'declaration' => 'accepted',
        ], ['declaration.accepted' => 'Vui lòng xác nhận thông tin nhận tiền đã nhập.'])->validate();
        $propertyId = ! empty($data['property_id']) ? (int) $data['property_id'] : null;
        if ($propertyId) {
            $workspace->properties()->findOrFail($propertyId);
        }
        $this->access->authorize($user, $id ? 'bank_account.update' : 'bank_account.create', $workspace, $propertyId);

        return SqlTransactions::run(function () use ($user, $workspace, $data, $propertyId, $id) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $record = $id ? ReceivingAccount::where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($id) : new ReceivingAccount(['workspace_id' => $workspace->id, 'created_by' => $user->id]);
            if ($id) {
                $this->access->authorize($user, 'bank_account.update', $workspace, $record->property_id);
                abort_unless($record->version === (int) $data['version'] && $record->status === 'active', 409, 'Tài khoản đã thay đổi. Vui lòng tải lại trước khi sửa.');
                if ($record->property_id !== $propertyId && DB::table('receiving_account_defaults')->where('receiving_account_id', $id)->exists()) {
                    throw ValidationException::withMessages(['property_id' => 'Hãy thay tài khoản mặc định trước khi thay đổi phạm vi tài khoản này.']);
                }
            }
            $bank = $this->catalog->bank($data['bank_bin']);
            $fingerprint = hash_hmac('sha256', $data['bank_bin'].':'.strtoupper($data['account_no']), config('app.key'));
            if (ReceivingAccount::where('workspace_id', $workspace->id)->where('account_fingerprint', $fingerprint)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['account_no' => 'Tài khoản này đã được khai báo trong khu quản lý.']);
            }
            $record->fill(collect($data)->only(['bank_bin', 'account_no', 'account_holder_name', 'label'])->all() + ['property_id' => $propertyId, 'bank_name' => $bank['short_name'].' · '.$bank['name'], 'account_fingerprint' => $fingerprint, 'verification_status' => 'not_checked', 'version' => $id ? $record->version + 1 : 1])->save();
            $this->journal->record($user->id, $id ? 'bank_account.updated' : 'bank_account.created', $record, $workspace->id, ['masked_number' => $record->masked_number, 'version' => $record->version]);

            return $record;
        });
    }

    public function setDefault(User $user, Workspace $workspace, int $id, ?int $propertyId, ?int $roomId = null): void
    {
        if ($roomId) {
            $room = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->findOrFail($roomId);
            $propertyId = $room->property_id;
        }
        if ($propertyId) {
            $workspace->properties()->findOrFail($propertyId);
        }
        $this->access->authorize($user, 'bank_account.set_default', $workspace, $propertyId, $roomId);
        SqlTransactions::run(function () use ($user, $workspace, $id, $propertyId, $roomId) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $account = ReceivingAccount::where('workspace_id', $workspace->id)->where('status', 'active')->findOrFail($id);
            abort_unless($account->property_id === null || $account->property_id === $propertyId, 403);
            DB::table('receiving_account_defaults')->updateOrInsert(['workspace_id' => $workspace->id, 'scope_key' => $roomId ? 'room:'.$roomId : ($propertyId ? 'property:'.$propertyId : 'workspace')], ['property_id' => $propertyId, 'room_id' => $roomId, 'receiving_account_id' => $id, 'updated_at' => now(), 'created_at' => now()]);
            $this->journal->record($user->id, 'bank_account.default_changed', $account, $workspace->id, ['property_id' => $propertyId]);
        });
    }

    public function disable(User $user, Workspace $workspace, int $id, int $version): void
    {
        SqlTransactions::run(function () use ($user, $workspace, $id, $version) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $account = ReceivingAccount::where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($id);
            $this->access->authorize($user, 'bank_account.disable', $workspace, $account->property_id);
            abort_unless($account->version === $version, 409, 'Tài khoản đã thay đổi. Vui lòng tải lại.');
            $account->update(['status' => 'disabled', 'disabled_at' => now(), 'version' => $account->version + 1]);
            DB::table('receiving_account_defaults')->where('receiving_account_id', $id)->delete();
            $this->journal->record($user->id, 'bank_account.disabled', $account, $workspace->id, ['masked_number' => $account->masked_number]);
        });
    }

    public function selected(Workspace $workspace, int $propertyId, ?int $roomId = null): ?ReceivingAccount
    {
        $workspace->properties()->findOrFail($propertyId);
        if ($roomId) {
            $workspace->properties()->findOrFail($propertyId)->rooms()->findOrFail($roomId);
        }
        $assignments = DB::table('receiving_account_defaults')->where('workspace_id', $workspace->id)->whereIn('scope_key', ['room:'.$roomId, 'property:'.$propertyId, 'workspace'])->get()->keyBy('scope_key');
        // A disabled/missing explicit override does not silently redirect its future payments.
        $assignment = ($roomId ? $assignments->get('room:'.$roomId) : null) ?? $assignments->get('property:'.$propertyId) ?? $assignments->get('workspace');

        return $assignment ? ReceivingAccount::where('workspace_id', $workspace->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', $propertyId))->find($assignment->receiving_account_id) : null;
    }
}
