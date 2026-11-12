<?php

namespace App\Modules\Subscription;

use App\Modules\Subscription\Models\SubscriptionCycle;
use App\Modules\Subscription\Models\SubscriptionQuote;
use App\Support\ProductPresenter;

/** Organizes existing subscription operations within the existing administration page. */
final class AdminPresenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $section = $this->request->input('section');
        if ($section === 'order') { $this->order(); return; }
        app(BusinessAccess::class)->authorize($this->user, $section === 'requests' ? 'subscriptions.view' : 'payments.view');
        abort_unless(in_array($section, ['requests','payments'], true), 404);
        $this->vm['tabs'] = [$this->link('Yêu cầu đăng ký / gia hạn', 'admin-dashboard', ['section' => 'requests']), $this->link('Thuê bao', 'admin-dashboard', ['section'=>'requests','tab'=>'subscriptions']), $this->link('Lịch sử đối soát', 'admin-dashboard', ['section' => 'payments']), $this->link('Hồ sơ gói cũ', 'admin-dashboard', ['section' => 'legacy'])];
        $this->vm['tabs'] = array_values(array_filter($this->vm['tabs'], fn($link) => app(BusinessAccess::class)->can($this->user, str_contains($link['url'],'section=legacy')?'subscription.approve':(str_contains($link['url'],'section=payments')?'payments.view':'subscriptions.view'))));
        if ($section === 'requests' && $this->request->input('tab') === 'subscriptions') { $this->subscriptions($q); return; }
        if ($section === 'requests') {
            $states = ['pending' => 'Chưa hoàn tất', 'unreported'=>'Chưa báo chuyển', 'reported'=>'Chờ xác nhận', 'expired'=>'Quá hạn chưa hoàn tất', 'confirmed' => 'Đã xử lý', 'cancelled' => 'Đã hủy'];
            $status = $this->request->validate(['status' => 'nullable|in:'.implode(',', array_keys($states))])['status'] ?? '';
            $this->vm['admin_filter'] = ['section' => 'requests', 'states' => $states];
            $query = SubscriptionQuote::with('accountSubscription.owner', 'planVersion')->when($status, fn ($query) => $query->when(in_array($status,['reported','unreported','expired']),fn($query)=>$query->where('status','pending')->when($status==='expired',fn($query)=>$query->where('expires_at','<=',now()),fn($query)=>$query->where('expires_at','>',now()))->when($status==='reported',fn($query)=>$query->whereNotNull('payer_reported_at'))->when($status==='unreported',fn($query)=>$query->whereNull('payer_reported_at')),fn($query)=>$query->where('status',$status)))->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('reference', 'like', '%'.$q.'%')->orWhereHas('accountSubscription.owner', fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'))));
            $this->records($query, fn ($quote) => $this->quoteCard($quote, [$this->link('Xem chi tiết','admin-dashboard',['section'=>'order','id'=>$quote->id])]));
            return;
        }
        if ($this->request->input('tab') !== 'history') {
            $states=['unreported'=>'Chưa báo chuyển','reported'=>'Chờ đối soát','confirmed'=>'Đã xác nhận thực nhận','cancelled'=>'Đã hủy','expired'=>'Quá hạn chưa hoàn tất','reversed'=>'Đã đảo giao dịch'];
            $status=$this->request->validate(['status'=>'nullable|in:'.implode(',',array_keys($states))])['status']??'';
            $this->vm['admin_filter'] = ['section'=>'payments','states'=>$states];
            $query = SubscriptionQuote::with('accountSubscription.owner','planVersion')->when($q!=='',fn($query)=>$query->where(fn($query)=>$query->where('reference','like','%'.$q.'%')->orWhereHas('accountSubscription.owner',fn($user)=>$user->where('name','like','%'.$q.'%')->orWhere('email','like','%'.$q.'%'))));
            if(in_array($status,['unreported','reported'],true)){$query->where('status','pending')->where('expires_at','>',now());$status==='reported'?$query->whereNotNull('payer_reported_at'):$query->whereNull('payer_reported_at');}
            elseif($status==='expired')$query->where('status','pending')->where('expires_at','<=',now());
            elseif($status==='confirmed')$query->where('status','confirmed')->whereIn('id',SubscriptionCycle::whereNull('reversed_at')->select('subscription_quote_id'));
            elseif($status==='reversed')$query->whereIn('id',SubscriptionCycle::whereNotNull('reversed_at')->select('subscription_quote_id'));
            elseif($status)$query->where('status',$status);
            $this->vm['links'][] = $this->link('Lịch sử khoản đã đối soát','admin-dashboard',['section'=>'payments','tab'=>'history']);
            $this->records($query, fn($quote)=>$this->quoteCard($quote,[$this->link('Xem chi tiết','admin-dashboard',['section'=>'order','id'=>$quote->id])]));return;
        }
        $this->vm['admin_filter'] = ['section' => 'payments', 'tab'=>'history', 'states' => []];
        $query = SubscriptionCycle::with('quote.planVersion', 'quote.accountSubscription.owner')->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('bank_reference', 'like', '%'.$q.'%')->orWhereHas('quote.accountSubscription.owner', fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'))));
        $this->records($query, function ($cycle) {
            $forms = ($cycle->reversed_at || !app(BusinessAccess::class)->can($this->user,'payments.verify')) ? [] : [$this->form('Ghi nhận giao dịch bị đảo đã xác minh', 'account.subscription.reverse', [$this->field('evidence', 'Căn cứ xác minh giao dịch bị đảo', 'textarea'), $this->field('confirm', 'Tôi đã xác minh giao dịch bị đảo', 'checkbox')], $cycle->id)];
            return $this->card($cycle->quote->accountSubscription->owner->name, $cycle->quote->planVersion->snapshot['label'], ['Thanh toán' => $cycle->reversed_at ? 'Đã ghi nhận giao dịch bị đảo' : 'Đã xác nhận thực nhận', 'Số tiền đã đối soát' => Vnd::format((string)$cycle->verified_amount), 'Ngày đối soát' => $cycle->verified_at->format('d/m/Y H:i'), 'Bắt đầu kỳ' => $cycle->starts_at->format('d/m/Y H:i'), 'Kết thúc kỳ' => $cycle->ends_at->format('d/m/Y H:i'), 'Áp dụng' => $cycle->reversed_at ? 'Đã đảo giao dịch' : ($cycle->application_state === 'scheduled' ? 'Chờ kỳ tiếp theo' : 'Đã áp dụng')], $forms);
        });
    }

    public function quoteCard(SubscriptionQuote $quote, array $links = []): array
    {
        $service = app(AccountSubscriptionService::class);
        $simulation=\App\Support\SimulationOrigin::contains('subscription_quotes',$quote->id);
        $cycle = SubscriptionCycle::where('subscription_quote_id', $quote->id)->first();
        $payment = $cycle ? ($cycle->reversed_at ? 'Đã ghi nhận giao dịch bị đảo' : 'Đã xác nhận thực nhận') : ($quote->payer_reported_at ? 'Chờ đối soát' : 'Chưa báo chuyển tiền');
        $forms = $service->payable($quote) && $quote->payer_reported_at ? [$this->form('Tôi đã nhận', 'account.subscription.confirm', [$this->field('amount', 'Số tiền thực nhận', 'number'), $this->field('reference', 'Mã giao dịch ngân hàng'), $this->field('note', 'Kết quả kiểm tra giao dịch thực nhận', 'textarea'), $this->field('confirm', 'Tôi đã kiểm tra giao dịch thực nhận, không chỉ ảnh chuyển khoản', 'checkbox')], $quote->id)] : [];
        return $this->card('Thanh toán gói dịch vụ', $quote->reference, ['Người đăng ký' => $quote->accountSubscription->owner->name, 'Gói' => $quote->planVersion->snapshot['label'], 'Trạng thái hồ sơ' => $quote->status==='pending'&&$quote->expires_at->lte(now())?'Quá hạn chưa hoàn tất':['pending' => 'Chưa hoàn tất', 'confirmed' => 'Đã xử lý', 'cancelled' => 'Đã hủy'][$quote->status] ?? 'Cần kiểm tra', 'Số tiền yêu cầu' => Vnd::format((string)$quote->amount), 'Thanh toán' => $payment, 'Số tiền đã đối soát' => $cycle ? Vnd::format((string)$cycle->verified_amount) : 'Chưa có', 'Người xác nhận'=>$cycle?\App\Models\User::find($cycle->verified_by)?->name??'Chưa có':'Chưa có', 'Ngày tạo' => $quote->created_at->format('d/m/Y H:i'), 'Phương thức'=>'Chuyển khoản', 'Số còn thiếu'=>$cycle?($cycle->reversed_at?'Cần đối soát':'0 ₫'):Vnd::format((string)$quote->amount), 'Báo chuyển lúc'=>$quote->payer_reported_at?->format('d/m/Y H:i')??'Chưa báo', 'Xác nhận lúc'=>$cycle?->verified_at?->format('d/m/Y H:i')??'Chưa xác nhận', 'Hạn thanh toán' => $quote->expires_at->format('d/m/Y H:i'), 'Mã giao dịch người mua cung cấp' => $quote->payer_bank_reference ?: 'Chưa có'], $forms, $links);
    }
    private function order(): void
    {
        app(BusinessAccess::class)->authorize($this->user,'orders.view');
        $data=$this->request->validate(['id'=>'required|integer|min:1']);
        $quote=SubscriptionQuote::with('planVersion','accountSubscription.owner')->findOrFail($data['id']);
        $this->vm['records'][]=$this->quoteCard($quote);
        $cycle=SubscriptionCycle::where('subscription_quote_id',$quote->id)->first();
        $this->vm['records'][]=$this->card('Điều kiện gói đã xác lập',$quote->planVersion->snapshot['label'],['Giá báo'=>Vnd::format((string)$quote->amount),'Phiên bản'=>$quote->plan_version_id,'Bắt đầu kỳ'=>$cycle?->starts_at?->format('d/m/Y H:i')??'Chưa xác lập; chốt khi xác nhận đủ tiền','Kết thúc kỳ'=>$cycle?->ends_at?->format('d/m/Y H:i')??'Chưa xác lập','Người xác nhận'=>$cycle?\App\Models\User::find($cycle->verified_by)?->name??'Chưa có':'Chưa có']);
        $history=\App\Modules\DataPlatform\Models\AuditEntry::where(fn($query)=>$query->where(fn($query)=>$query->where('resource_type',$quote->getMorphClass())->where('resource_id',$quote->id))->when($cycle,fn($query)=>$query->orWhere(fn($query)=>$query->where('resource_type',$cycle->getMorphClass())->where('resource_id',$cycle->id))));
        foreach($history->oldest('id')->get()as$entry)$this->vm['records'][]=$this->card(['subscription.quote.created'=>'Tạo yêu cầu','subscription.payment.reported'=>'Người mua báo chuyển tiền','subscription.quote.cancelled'=>'Hủy yêu cầu','subscription.account.verified'=>'Đã xác nhận thực nhận','subscription.change.scheduled'=>'Đã xác nhận gói cho kỳ tiếp theo','subscription.change.applied'=>'Đã áp dụng gói theo lịch','subscription.account.verified_reversal'=>'Đã đảo giao dịch'][$entry->action]??'Cập nhật hồ sơ',$entry->created_at->format('d/m/Y H:i'),['Người thực hiện'=>$entry->actor_id?\App\Models\User::find($entry->actor_id)?->name??'Không còn hoạt động':'Hệ thống']);
        $this->vm['links'][]=$this->link('Quay lại danh sách','admin-dashboard',['section'=>app(BusinessAccess::class)->can($this->user,'subscriptions.view')?'requests':(app(BusinessAccess::class)->can($this->user,'payments.view')?'payments':'revenue')]);
    }

    private function subscriptions(string $q): void
    {
        $states=['trial'=>'Dùng thử','active'=>'Hoạt động','pending'=>'Chờ kích hoạt','expiring'=>'Sắp hết hạn','expired'=>'Hết hạn','suspended'=>'Tạm đình chỉ'];
        $status=$this->request->validate(['status'=>'nullable|in:'.implode(',',array_keys($states))])['status']??'';
        $this->vm['admin_filter']=['section'=>'requests','tab'=>'subscriptions','states'=>$states];
        $query=\App\Modules\Subscription\Models\AccountSubscription::with('owner','planVersion')->whereNotNull('authority_enabled_at')->when($q!=='',fn($query)=>$query->whereHas('owner',fn($owner)=>$owner->where('name','like','%'.$q.'%')->orWhere('email','like','%'.$q.'%')));
        if($status==='expired')$query->whereNotIn('status',['pending','suspended'])->where('expires_at','<=',now());
        elseif($status==='expiring')$query->whereIn('status',['trial','active'])->where('expires_at','>',now())->where('expires_at','<=',now()->addDays(config('platform_business.expiring_days')));
        elseif($status)$query->where('status',$status)->when(in_array($status,['trial','active'],true),fn($query)=>$query->where('expires_at','>',now()));
        $this->records($query,function($account){
            $next=app(AccountSubscriptionService::class)->scheduled($account);$status=in_array($account->status,['trial','active'],true)&&$account->expires_at->lte(now())?'expired':$account->status;
            $start=$account->trial_started_at;
            if($account->status!=='trial')$start=SubscriptionCycle::where('account_subscription_id',$account->id)->where('application_state','applied')->whereNull('reversed_at')->latest('starts_at')->first()?->starts_at;
            return $this->card($account->owner->name,$account->owner->email,['Gói hiện tại'=>$account->planVersion->snapshot['label'],'Trạng thái'=>self::label($status),'Bắt đầu kỳ'=>$start?->format('d/m/Y H:i')??'Chưa có kỳ hiệu lực đã xác lập','Hết hạn'=>$account->expires_at->format('d/m/Y H:i'),'Gói kỳ tiếp theo'=>$next?->quote?->planVersion?->snapshot['label']??'Chưa có','Ngày dự kiến'=>$next?->starts_at?->format('d/m/Y H:i')??'Chưa có','Áp dụng'=>$next?'Chờ tác vụ áp dụng theo lịch':'Theo gói hiện tại']);
        });
    }

}
