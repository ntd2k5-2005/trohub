<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\Models\SubscriptionQuote;
use App\Support\SqlTransactions;
use Illuminate\Support\Facades\{DB, Schema, Validator};

final class PlatformBankService
{
    public const AUTHORITY = 'platform_bank_database_authority';

    public function ready(): bool { return Schema::hasColumn('receiving_accounts','platform_default') && Schema::hasColumn('subscription_quotes','receiving_account_id'); }
    public function accounts() { return ReceivingAccount::whereNull('workspace_id')->whereNull('property_id'); }
    public function managed(): bool { return $this->ready() && DB::table('system_settings')->where('key',self::AUTHORITY)->exists(); }

    public function selected(bool $lock=false): ?ReceivingAccount
    {
        return $this->ready() ? $this->accounts()->where('platform_default','platform')->where('status','active')->when($lock,fn($query)=>$query->lockForUpdate())->first() : null;
    }

    private function lock(User $actor, string $permission): void
    {
        abort_unless($this->ready(),503,'Quản lý ngân hàng đang chờ cập nhật dữ liệu an toàn.');
        Permission::where('code',config('authorization.bootstrap_permission'))->lockForUpdate()->firstOrFail();
        abort_unless(app(Authorization::class)->global($actor,$permission),403);
    }

    private function takeAuthority(): void
    {
        DB::table('system_settings')->updateOrInsert(['key'=>self::AUTHORITY],['value'=>'true','created_at'=>now(),'updated_at'=>now()]);
    }

    public function save(User $actor, array $input, ?int $id=null): ReceivingAccount
    {
        $permission=$id?'bank_account.update':'bank_account.create';
        abort_unless(app(Authorization::class)->global($actor,$permission),403);
        $data=Validator::make($input,['label'=>'required|string|max:100','bank_bin'=>['required','regex:/^\d{6}$/'],'account_no'=>['required','string','regex:/^[A-Za-z0-9]{1,19}$/'],'account_holder_name'=>'required|string|min:2|max:100','version'=>$id?'required|integer|min:1':'nullable|integer','declaration'=>'accepted'])->validate();
        return SqlTransactions::run(function()use($actor,$data,$id,$permission){
            $this->lock($actor,$permission);$account=$id?$this->accounts()->lockForUpdate()->findOrFail($id):new ReceivingAccount(['workspace_id'=>null,'property_id'=>null,'created_by'=>$actor->id]);
            if($id)abort_unless($account->status==='active'&&$account->version===(int)$data['version'],409,'Tài khoản đã đổi hoặc ngừng sử dụng.');
            $bank=app(BankCatalog::class)->bank($data['bank_bin']);$fingerprint=$this->fingerprint($data['bank_bin'],$data['account_no']);
            abort_if($this->accounts()->where('platform_fingerprint',$fingerprint)->when($id,fn($q)=>$q->whereKeyNot($id))->exists(),422,'Tài khoản đã tồn tại trong danh sách nền tảng.');
            if($id)$this->linkExistingQuotes($account);
            $account->fill(collect($data)->only(['label','bank_bin','account_no','account_holder_name'])->all()+['bank_name'=>$bank['short_name'].' · '.$bank['name'],'account_fingerprint'=>$fingerprint,'platform_fingerprint'=>$fingerprint,'version'=>$id?$account->version+1:1])->save();
            $this->takeAuthority();$this->linkExistingQuotes($account);
            app(Journal::class)->record($actor->id,$id?'platform_bank.updated':'platform_bank.created',$account,null,['masked_number'=>$account->masked_number]);
            abort_unless(app(Authorization::class)->global($actor,$permission),403);return $account->fresh();
        });
    }

    public function change(User $actor,int $id,int $version,string $operation): void
    {
        $permission=match($operation){'default'=>'bank_account.set_default','disable'=>'bank_account.disable','delete'=>'bank_account.delete',default=>abort(422)};
        abort_unless(app(Authorization::class)->global($actor,$permission),403);
        SqlTransactions::run(function()use($actor,$id,$version,$operation,$permission){
            $this->lock($actor,$permission);$account=$this->accounts()->lockForUpdate()->findOrFail($id);
            abort_unless($account->version===$version,409,'Thông tin đã thay đổi. Vui lòng tải lại.');
            $this->linkExistingQuotes($account);$this->takeAuthority();
            if($operation==='default'){
                abort_unless($account->status==='active',409,'Tài khoản đã ngừng sử dụng.');
                $this->accounts()->where('platform_default','platform')->whereKeyNot($id)->update(['platform_default'=>null,'version'=>DB::raw('version+1'),'updated_at'=>now()]);
                $account->update(['platform_default'=>'platform','version'=>$version+1]);
            }elseif($operation==='disable')$account->update(['status'=>'disabled','disabled_at'=>now(),'platform_default'=>null,'version'=>$version+1]);
            else{
                abort_if(SubscriptionQuote::where('receiving_account_id',$id)->exists(),409,'Tài khoản có hồ sơ thanh toán. Hãy ngừng sử dụng để giữ lịch sử.');
                abort_if($account->platform_default,409,'Hãy đổi hoặc ngừng tài khoản mặc định trước khi xóa.');
                app(Journal::class)->record($actor->id,'platform_bank.deleted',$account,null,['masked_number'=>$account->masked_number]);$account->delete();
            }
            if($operation!=='delete')app(Journal::class)->record($actor->id,'platform_bank.'.$operation,$account);
            abort_unless(app(Authorization::class)->global($actor,$permission),403);
        });
    }

    public function fingerprint(string $bin,string $number): string { return hash_hmac('sha256',$bin.':'.strtoupper($number),config('app.key')); }

    private function linkExistingQuotes(ReceivingAccount $account): void
    {
        foreach(SubscriptionQuote::whereNull('receiving_account_id')->whereNotNull('payee_snapshot')->cursor()as$quote){
            $payee=$quote->payee_snapshot;
            if(isset($payee['bank_bin'],$payee['account_no'])&&hash_equals($account->account_fingerprint,$this->fingerprint($payee['bank_bin'],$payee['account_no'])))DB::table('subscription_quotes')->where('id',$quote->id)->whereNull('receiving_account_id')->update(['receiving_account_id'=>$account->id]);
        }
    }

    /** Explicit operator action, never triggered by a GET or seeder. */
    public function adoptConfigured(User $actor): ?ReceivingAccount
    {
        abort_unless(app(Authorization::class)->global($actor,'bank_account.create')&&app(Authorization::class)->global($actor,'bank_account.set_default'),403);
        return SqlTransactions::run(function()use($actor){
            $this->lock($actor,'bank_account.create');
            if($this->managed())return $this->selected();
            $payee=app(\App\Modules\Subscription\PlatformPayee::class)->configured();abort_unless($payee,409,'Chưa có cấu hình nhận tiền hợp lệ.');
            $fingerprint=$this->fingerprint($payee['bank_bin'],$payee['account_no']);$account=$this->accounts()->where('platform_fingerprint',$fingerprint)->first();
            if(!$account)$account=ReceivingAccount::create(['workspace_id'=>null,'property_id'=>null,'bank_bin'=>$payee['bank_bin'],'bank_name'=>$payee['bank_name'],'account_no'=>$payee['account_no'],'account_holder_name'=>$payee['account_holder_name'],'label'=>'Nhận tiền gói '.config('product.name'),'account_fingerprint'=>$fingerprint,'platform_fingerprint'=>$fingerprint,'created_by'=>$actor->id]);
            $account->refresh();$this->change($actor,$account->id,$account->version,'default');$this->linkExistingQuotes($account);
            app(Journal::class)->record($actor->id,'platform_bank.config_adopted',$account,null,['masked_number'=>$account->masked_number,'financial_history_changed'=>false]);return $account->fresh();
        });
    }
}
