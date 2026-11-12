<?php

namespace App\Modules\Subscription;

use App\Modules\Billing\{BankCatalog,PlatformBankService};
use App\Support\ProductPresenter;

final class PlatformBankPresenter extends ProductPresenter
{
    protected function build(string $slug,string $q): void
    {
        $service=app(PlatformBankService::class);
        $abilities=['bank_account.view','bank_account.create','bank_account.update','bank_account.disable','bank_account.set_default','bank_account.delete'];
        abort_unless(collect($abilities)->contains(fn($p)=>$this->access->global($this->user,$p)),403);
        $this->vm['notice']='Nhận tiền mua gói '.config('product.name').'. Thay đổi tài khoản mặc định chỉ áp dụng cho yêu cầu mới.';
        if(!$service->managed()&&$this->access->global($this->user,'bank_account.view'))$this->vm['platform_payee']=app(PlatformPayee::class)->summary();
        if(!$service->ready()){$this->vm['notice']='Quản lý ngân hàng đang chờ cập nhật dữ liệu an toàn.';return;}
        if(!$service->managed()&&$this->access->global($this->user,'bank_account.create')&&$this->access->global($this->user,'bank_account.set_default'))$this->vm['forms'][]=$this->form('Tiếp nhận tài khoản đang cấu hình','platform.bank.adopt',[$this->field('confirm','Giữ tài khoản đang dùng làm tài khoản mặc định, không đổi hồ sơ đã phát hành','checkbox')]);
        $catalog=app(BankCatalog::class)->list();$banks=collect($catalog['banks'])->mapWithKeys(fn($bank)=>[$bank['bin']=>$bank['short_name'].' · '.$bank['name']])->all();
        if($this->access->global($this->user,'bank_account.create'))$this->vm['forms'][]=$this->form('Thêm tài khoản nền tảng','platform.bank.save',$this->fields($banks));
        if(!$this->access->global($this->user,'bank_account.view'))return;
        $this->vm['admin_filter']=['section'=>'banking','states'=>[]];
        $this->records($service->accounts()->when($q!=='',fn($query)=>$query->where('label','like','%'.$q.'%')),function($account)use($banks){
            $forms=[];
            if($account->status==='active'){
                if($this->access->global($this->user,'bank_account.update'))$forms[]=$this->form('Sửa tài khoản nền tảng','platform.bank.save',$this->fields($banks,$account),$account->id);
                foreach(['default'=>['bank_account.set_default','Đặt làm mặc định'],'disable'=>['bank_account.disable','Ngừng sử dụng']]as$operation=>[$permission,$label])if($this->access->global($this->user,$permission)&&($operation!=='default'||!$account->platform_default))$forms[]=$this->form($label,'platform.bank.'.$operation,[$this->field('version','','hidden',value:$account->version),$this->field('confirm','Tôi đã kiểm tra và xác nhận thay đổi','checkbox')],$account->id);
            }
            if(!$account->platform_default&&$this->access->global($this->user,'bank_account.delete')&&!\App\Modules\Subscription\Models\SubscriptionQuote::where('receiving_account_id',$account->id)->exists())$forms[]=$this->form('Xóa tài khoản chưa sử dụng','platform.bank.delete',[$this->field('version','','hidden',value:$account->version),$this->field('confirm','Tài khoản chưa có hồ sơ thanh toán liên quan','checkbox')],$account->id);
            return $this->card($account->label,$account->bank_name,['BIN ngân hàng'=>$account->bank_bin,'Số tài khoản'=>$account->masked_number,'Tên chủ tài khoản'=>$account->account_holder_name,'Trạng thái'=>$account->status==='active'?'Đang hoạt động':'Đã ngừng sử dụng','Mặc định'=>$account->platform_default?'Đang nhận tiền gói':'Không'],$forms);
        });
    }
    private function fields(array $banks,$account=null): array
    {
        return [$this->field('label','Tên gợi nhớ',value:$account?->label),$this->field('bank_bin','Ngân hàng','search-select',$banks,$account?->bank_bin),$this->field('account_no','Số tài khoản',value:$account?->account_no),$this->field('account_holder_name','Tên chủ tài khoản',value:$account?->account_holder_name),$this->field('version','','hidden',value:$account?->version??1),$this->field('declaration','Tôi đã kiểm tra đúng tài khoản nhận tiền nền tảng','checkbox')];
    }
}
