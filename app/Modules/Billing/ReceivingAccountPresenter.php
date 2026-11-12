<?php

namespace App\Modules\Billing;

use App\Support\ProductPresenter;
use Illuminate\Support\Facades\DB;

final class ReceivingAccountPresenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->vm['banking'] = true;
        $catalog = app(BankCatalog::class)->list();
        $this->vm['bank_catalog'] = $catalog;
        $banks = collect($catalog['banks'])->mapWithKeys(fn ($b) => [$b['bin'] => $b['short_name'].' · '.$b['name']])->all();
        $createProps = $this->propertyOptions('bank_account.create');
        $all = $this->access->allowed($this->user, 'bank_account.create', $this->workspace);
        if ($all || $createProps) {
            $this->vm['forms'][] = $this->form('Thêm tài khoản nhận tiền', 'bank.save', $this->fields($banks, $createProps, $all));
        }
        $defaults = DB::table('receiving_account_defaults')->where('workspace_id', $this->workspace->id)->get();
        $props = $this->propertyOptions('bank_account.view');
        $roomNames = \App\Modules\Property\Models\Room::whereIn('property_id', array_keys($props))->get()->mapWithKeys(fn ($room) => [$room->id => $room->name.' · '.$props[$room->property_id]])->all();
        $defaults = $defaults->filter(fn ($d) => $d->room_id ? isset($roomNames[$d->room_id]) : (! $d->property_id || isset($props[$d->property_id])));
        $query = app(ReceivingAccountRepository::class)->visible($this->user, $this->workspace)->where('label', 'like', '%'.$q.'%');
        $this->records($query->latest(), function ($account) use ($banks, $defaults, $props, $roomNames) {
            $forms = [];
            if ($account->status === 'active') {
                if ($this->access->allowed($this->user, 'bank_account.update', $this->workspace, $account->property_id)) {
                    $forms[] = $this->form('Sửa tài khoản nhận tiền', 'bank.save', $this->fields($banks, $this->propertyOptions('bank_account.update'), $this->access->allowed($this->user, 'bank_account.update', $this->workspace), $account), $account->id);
                }
                $defaultProps = $this->propertyOptions('bank_account.set_default');
                if ($account->property_id) {
                    $defaultProps = array_intersect_key($defaultProps, [$account->property_id => true]);
                }
                $all = ! $account->property_id && $this->access->allowed($this->user, 'bank_account.set_default', $this->workspace);
                if ($all || $defaultProps) {
                    $forms[] = $this->form('Chọn làm tài khoản mặc định', 'bank.default', [$this->field('property_id', 'Nhà nhận tiền (để trống để áp dụng toàn khu)', 'select', $defaultProps, required: ! $all), $this->field('room_id', 'Phòng nhận tiền riêng (nếu có)', 'select', $this->roomOptions('bank_account.set_default'), required: false)], $account->id);
                }
                if ($this->access->allowed($this->user, 'bank_account.disable', $this->workspace, $account->property_id)) {
                    $forms[] = $this->form('Ngừng dùng tài khoản', 'bank.disable', [$this->field('version', '', 'hidden', value: $account->version), $this->field('confirm', 'Tôi hiểu các khoản thu cũ vẫn giữ thông tin nhận tiền đã phát hành', 'checkbox')], $account->id);
                }
            }
            $used = $defaults->where('receiving_account_id', $account->id)->map(fn ($d) => $d->room_id ? $roomNames[$d->room_id] : ($d->property_id ? ($props[$d->property_id] ?? 'Nhà trọ') : 'Toàn khu quản lý'))->implode(', ');

            return $this->card($account->label, $account->bank_name, ['Chủ tài khoản' => $account->account_holder_name, 'Số tài khoản' => $account->masked_number, 'Phạm vi' => $account->property_id ? ($props[$account->property_id] ?? 'Nhà trọ') : 'Toàn khu quản lý', 'Trạng thái' => $account->status === 'active' ? 'Đang sử dụng' : 'Đã ngừng sử dụng', 'Thông tin ngân hàng' => 'Do chủ trọ khai báo', 'Mặc định cho' => $used ?: 'Chưa chọn'], $forms);
        });
    }

    private function fields(array $banks, array $properties, bool $all, $account = null): array
    {
        return [$this->field('label', 'Tên gợi nhớ', value: $account?->label), $this->field('bank_bin', 'Ngân hàng', 'search-select', $banks, $account?->bank_bin), $this->field('account_no', 'Số tài khoản', value: $account?->account_no), $this->field('account_holder_name', 'Tên chủ tài khoản', value: $account?->account_holder_name), $this->field('property_id', 'Phạm vi sử dụng', 'select', ($all ? ['' => 'Toàn khu quản lý'] : []) + $properties, $account?->property_id, ! $all), $this->field('version', '', 'hidden', value: $account?->version ?? 1), $this->field('declaration', 'Tôi đã kiểm tra thông tin nhận tiền và chịu trách nhiệm về thông tin đã khai báo', 'checkbox')];
    }
}
