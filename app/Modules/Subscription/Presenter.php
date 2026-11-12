<?php

namespace App\Modules\Subscription;

use App\Modules\Subscription\Models\{SubscriptionOrder, SubscriptionQuote, SubscriptionCycle};
use App\Modules\Workspace\Models\Workspace;
use App\Support\{ProductPresenter,Vnd};
use Illuminate\Support\Str;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $owner = $this->workspace?->owner_id ?? $this->user->id;
        $service = app(AccountSubscriptionService::class);
        $account = $service->current($owner);
        $isOwner = $owner === $this->user->id;
        $paymentId=$this->request->validate(['payment_id'=>'nullable|integer|min:1'])['payment_id']??null;
        abort_if($paymentId&&!$isOwner,403);
        if ($isOwner) $this->vm['platform_payee'] = app(PlatformPayee::class)->summary();
        if ($this->workspace) $this->vm['links'][] = ['label' => 'Quản lý gói dùng chung', 'url' => route('ui.show', ['slug' => 'landlord-upgrade', 'context' => 'personal'])];
        if ($account) {
            $this->vm['stats'] = ['Gói hiện tại' => $account->planVersion->snapshot['label'], 'Hết hạn' => $account->expires_at->format('d/m/Y H:i'), 'Trạng thái' => self::label(in_array($account->status,['trial','active'])&&$account->expires_at->lte(now())?'expired':$account->status)];
            $next = $service->scheduled($account);
            if ($next) $this->vm['records'][] = $this->card('Gói kỳ tiếp theo', $next->quote->planVersion->snapshot['label'], ['Bắt đầu' => $next->starts_at->format('d/m/Y H:i'), 'Kết thúc' => $next->ends_at->format('d/m/Y H:i'), 'Thanh toán' => 'Đã xác nhận thực nhận', 'Trạng thái' => 'Chờ đến kỳ áp dụng']);
            if (!$isOwner) return;
            foreach (app(LegacySubscriptionReview::class)->originalPeriods($owner) as $period) $this->vm['records'][] = $this->card('Lịch sử kỳ gói trước', config('subscriptions.plans.'.$period['plan'].'.label'), ['Hết hạn đã ghi nhận' => $period['expires_at'] ? \Illuminate\Support\Carbon::parse($period['expires_at'])->format('d/m/Y H:i') : 'Chưa xác định', 'Trạng thái đã ghi nhận' => self::label($period['status'])]);
            $quotes=SubscriptionQuote::with('planVersion','accountSubscription')->where('account_subscription_id',$account->id)->when($paymentId,fn($q)=>$q->whereKey($paymentId));
            $this->vm['subscription_payments']=$quotes->latest()->limit(30)->get()->map(fn($quote)=>['quote'=>$quote,'payable'=>$service->payable($quote),'proposed_start'=>$account->expires_at->isFuture()?$account->expires_at:now()])->all();
            if($paymentId){abort_unless(count($this->vm['subscription_payments'])===1,404);$this->vm['subscription_payment_detail']=$this->vm['subscription_payments'][0];return;}
            if (in_array($account->status, ['active', 'trial'], true) && $account->expires_at->isFuture()) $this->vm['forms'][] = $this->form('Tạo thêm khu quản lý', 'account.workspace.create', [$this->field('name', 'Tên khu quản lý')]);
            $plans = $this->plans($account->planVersion->plan);
            if ($plans && !$next && $account->status !== 'suspended') $this->vm['forms'][] = $this->form('Chọn gói hoặc gia hạn', 'account.quote', [$this->field('plan', 'Gói dịch vụ', 'select', $plans), $this->field('reference', '', 'hidden', value: (string) Str::uuid())]);
            $this->vm['notice'] = 'Chỉ gửi yêu cầu sẽ không thay đổi gói đang dùng. Chuyển gói trong kỳ trả phí được áp dụng từ kỳ tiếp theo sau khi xác nhận thực nhận; không tính tiền chênh lệch giữa kỳ.';
            foreach (SubscriptionCycle::with('quote.planVersion')->where('account_subscription_id', $account->id)->latest()->limit(30)->get() as $cycle) $this->vm['records'][] = $this->card('Lịch sử kỳ gói', $cycle->quote->planVersion->snapshot['label'], ['Bắt đầu' => $cycle->starts_at->format('d/m/Y H:i'), 'Kết thúc' => $cycle->ends_at->format('d/m/Y H:i'), 'Số tiền đã đối soát' => self::money($cycle->verified_amount), 'Trạng thái' => $cycle->reversed_at ? 'Đã ghi nhận giao dịch bị đảo' : ($cycle->application_state === 'scheduled' ? 'Chờ kỳ tiếp theo' : 'Đã áp dụng')]);
            return;
        }
        $workspaces = Workspace::where('owner_id', $owner)->whereNull('purged_at')->get();
        abort_if($paymentId,404);
        $this->vm['notice'] = 'Quản lý gói đang sử dụng và xem lịch sử đăng ký của bạn.';
        if ($this->workspace) $this->vm['stats'] = ['Gói hiện tại' => config('subscriptions.plans.'.$this->workspace->plan.'.label'), 'Hết hạn' => $this->workspace->expires_at?->format('d/m/Y H:i') ?? 'Cần đối chiếu', 'Trạng thái' => self::label($this->workspace->effective_status)];
        if (!$isOwner) return;
        foreach ($workspaces as $workspace) $this->vm['records'][] = $this->card($workspace->name, 'Kỳ gói hiện có', ['Gói' => config('subscriptions.plans.'.$workspace->plan.'.label'), 'Hết hạn' => $workspace->expires_at?->format('d/m/Y H:i') ?? 'Cần quản trị viên đối chiếu', 'Trạng thái' => self::label($workspace->effective_status)]);
        foreach (SubscriptionOrder::whereIn('workspace_id', $workspaces->pluck('id'))->latest()->limit(30)->get() as $order) $this->vm['records'][] = $this->card('Lịch sử đăng ký', config('subscriptions.plans.'.$order->plan.'.label'), ['Ngày đăng ký' => $order->created_at->format('d/m/Y'), 'Trạng thái' => self::label($order->status), 'Chứng từ' => 'Chưa có khoản thanh toán đã xác nhận']);
        $history=\App\Modules\Subscription\Models\AccountSubscription::where('owner_id',$owner)->whereNull('authority_enabled_at')->first();
        if($history)foreach(SubscriptionCycle::with('quote.planVersion')->where('account_subscription_id',$history->id)->latest()->limit(30)->get()as$cycle)$this->vm['records'][]=$this->card('Lịch sử mua gói',$cycle->quote->planVersion->snapshot['label'],['Bắt đầu'=>$cycle->starts_at->format('d/m/Y'),'Kết thúc'=>$cycle->ends_at->format('d/m/Y'),'Số tiền'=>Vnd::format((string)$cycle->verified_amount)]);
        if (!config('subscriptions.account_authority_enabled')) return;
        if ($workspaces->isEmpty() && app(PlanCatalog::class)->selectable('trial')) $this->vm['forms'][] = $this->form('Bắt đầu dùng thử', 'account.trial');
        try { app(LegacySubscriptionReview::class)->requireEnded($owner); $eligible = true; } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $eligible = false; }
        $plans = $this->plans();
        if ($eligible && $plans) $this->vm['forms'][] = $this->form('Đăng ký gói mới', 'account.paid.start', [$this->field('plan', 'Gói dịch vụ', 'select', $plans), $this->field('reference', '', 'hidden', value: (string) Str::uuid())]);
        if (!$plans) $this->vm['notice'] .= ' Hiện chưa có gói mới để đăng ký. Bạn vẫn sử dụng gói hiện tại đến hết hạn.';
    }

    private function plans(?string $current = null): array
    {
        return collect(array_keys(config('subscriptions.plans')))->reject(fn ($key) => $key === 'trial')->map(fn ($key) => app(PlanCatalog::class)->selectable($key, $key === $current))->filter()->mapWithKeys(fn ($plan) => [$plan->plan => $plan->snapshot['label'].' · '.self::money($plan->snapshot['price'])])->all();
    }
}
