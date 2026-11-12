<?php
namespace App\Modules\Subscription;
use App\Models\User;
use App\Modules\Subscription\Models\{AccountSubscription,SubscriptionQuote,SubscriptionOrder};
use App\Modules\Workspace\Models\Workspace;
use App\Support\{DashboardCharts,ProductPresenter,SimulationOrigin};

final class BusinessPresenter extends ProductPresenter
{
    protected function build(string $slug,string $q):void
    {
        if($this->request->input('section')==='revenue'){$this->revenue();return;}
        $access=app(BusinessAccess::class);$can=[];
        foreach(['subscription.revenue.view','subscriptions.view','payments.view','subscription.approve']as$ability)$can[$ability]=$access->can($this->user,$ability);
        abort_unless($can['subscription.revenue.view']||$can['subscriptions.view']||$can['payments.view'],403);
        $period=BusinessPeriod::request($this->request);$totals=$can['subscription.revenue.view']?app(RevenueRepository::class)->totals($this->user,$period):null;
        $reported=SubscriptionQuote::where('status','pending')->where('expires_at','>',now())->whereNotNull('payer_reported_at');
        $unreported=SubscriptionQuote::where('status','pending')->where('expires_at','>',now())->whereNull('payer_reported_at');
        $accounts=AccountSubscription::with('planVersion','owner')->whereNotNull('authority_enabled_at');
        $live=(clone$accounts)->whereIn('status',['active','trial'])->where('expires_at','>',now());
        $legacy=Workspace::whereNull('purged_at')->where('expires_at','>',now())->whereNotIn('owner_id',AccountSubscription::whereNotNull('authority_enabled_at')->select('owner_id'));
        $newCount=$can['subscriptions.view']?(clone$live)->count():0;$legacyCount=$can['subscriptions.view']?(clone$legacy)->distinct()->count('owner_id'):0;
        $reportedCount=$can['payments.view']?(clone$reported)->count():0;$unreportedCount=$can['subscriptions.view']?(clone$unreported)->count():0;
        $legacyPending=$can['subscription.approve']?SubscriptionOrder::where('status','pending')->count():0;
        $d=['kind'=>'platform','period'=>$period->parameters(),'scope'=>null,'kpis'=>[
            ['label'=>'Tiền gói đã ghi nhận trong kỳ','value'=>$totals?Vnd::format($totals['received']):'Chưa có quyền xem','note'=>'Theo ngày xác nhận khoản nhận'],
            ['label'=>'Giao dịch chờ xác nhận','value'=>$can['payments.view']?$reportedCount:'Chưa có quyền xem','note'=>'Đã báo chuyển, còn hạn xử lý'],
            ['label'=>'Tài khoản có gói còn hiệu lực','value'=>$can['subscriptions.view']?$newCount+$legacyCount:'Chưa có quyền xem','note'=>$can['subscriptions.view']?$newCount.' gói tài khoản · '.$legacyCount.' tài khoản theo kỳ cũ':''],
            ['label'=>'Hồ sơ cần xử lý','value'=>$can['subscriptions.view']||$can['subscription.approve']?$unreportedCount+$legacyPending:'Chưa có quyền xem','note'=>'Chưa báo chuyển hoặc chờ đối chiếu kỳ cũ'],
        ],'charts'=>[],'worklists'=>[],'simulation'=>$totals&&$totals['simulation_received']!=='0','simulation_received'=>$totals['simulation_received']??'0','detail'=>[],'links'=>[],'quality_warning'=>null,'metrics'=>['received'=>$totals['received']??null,'reported'=>$reportedCount,'unreported'=>$unreportedCount,'live_accounts'=>$newCount+$legacyCount,'legacy_accounts'=>$legacyCount,'legacy_periods'=>$can['subscriptions.view']?(clone$legacy)->count():0]];
        $limit=config('dashboards.preview_limit');
        if($totals){$d['charts'][]=DashboardCharts::time('Tiền gói đã ghi nhận theo thời gian',$totals['series']);$d['links'][]=$this->link('Doanh thu và báo cáo','admin-dashboard',['section'=>'revenue']+$period->parameters());}
        if($can['subscriptions.view']){
            $values=[];foreach(['trial'=>'Dùng thử','monthly'=>'Theo tháng','yearly'=>'Theo năm']as$key=>$label)$values[$label]=(clone$live)->whereHas('planVersion',fn($p)=>$p->where('plan',$key))->count();$values['Theo kỳ gói cũ']=$legacyCount;
            $d['charts'][]=DashboardCharts::distribution('Tài khoản có gói còn hiệu lực',$values);
            $d['detail'][]=['label'=>'Kỳ gói cũ còn hiệu lực','value'=>(clone$legacy)->count()];
            $d['detail'][]=['label'=>'Gói tài khoản đã hết hạn','value'=>(clone$accounts)->whereNotIn('status',['pending','suspended'])->where('expires_at','<=',now())->count()];
            $expiring=(clone$live)->where('expires_at','<=',now()->addDays(config('platform_business.expiring_days')));
            $d['detail'][]=['label'=>'Gói tài khoản sắp hết hạn','value'=>(clone$expiring)->count()];
            $d['worklists'][]=['title'=>'Gói tài khoản sắp hết hạn','items'=>$expiring->orderBy('expires_at')->limit($limit)->get()->map(fn($a)=>['title'=>$a->owner->name,'subtitle'=>$a->planVersion->snapshot['label'],'value'=>$a->expires_at->format('d/m/Y'),'url'=>$this->link('Xem thuê bao','admin-dashboard',['section'=>'requests','tab'=>'subscriptions','q'=>$a->owner->email])['url']])->all()];
            $d['links'][]=$this->link('Đăng ký và thuê bao','admin-dashboard',['section'=>'requests']);
        }
        $queues=[];$links=[];
        foreach([['payments.view','Chờ xác nhận tiền',$reported,$reportedCount,'payments','reported'],['subscriptions.view','Chưa báo chuyển tiền',$unreported,$unreportedCount,'requests','unreported']]as[$ability,$title,$query,$count,$section,$status]){
            if(!$can[$ability])continue;$url=$this->link($title,'admin-dashboard',['section'=>$section,'status'=>$status])['url'];$queues[$title]=$count;$links[$title]=$url;
            $d['worklists'][]=['title'=>$title,'url'=>$url,'items'=>$query->with('accountSubscription.owner','planVersion')->oldest()->limit($limit)->get()->map(fn($a)=>['title'=>$a->accountSubscription->owner->name,'subtitle'=>$a->planVersion->snapshot['label'],'value'=>Vnd::format((string)$a->amount),'url'=>$this->link('Xem giao dịch','admin-dashboard',['section'=>'order','id'=>$a->id])['url']])->all()];
        }
        if($can['subscription.approve']){$queues['Chờ đối chiếu kỳ cũ']=$legacyPending;$links['Chờ đối chiếu kỳ cũ']=$this->link('Đối chiếu kỳ cũ','admin-dashboard',['section'=>'legacy'])['url'];$d['links'][]=['label'=>'Hồ sơ kỳ gói cũ','url'=>$links['Chờ đối chiếu kỳ cũ']];}
        if($queues)$d['charts'][]=DashboardCharts::horizontal('Công việc cần xử lý',$queues,$links);
        $this->vm['dashboard']=$d;
    }
    private function revenue():void
    {
        app(BusinessAccess::class)->authorize($this->user,'subscription.revenue.view');$period=BusinessPeriod::request($this->request);$repository=app(RevenueRepository::class);$totals=$repository->totals($this->user,$period);
        $this->vm['business']=['period'=>$period->parameters()]+$totals+['plans'=>array_filter(['Theo tháng'=>$totals['monthly'],'Theo năm'=>$totals['yearly']],fn($v)=>$v!=='0')];
        if(app(BusinessAccess::class)->can($this->user,'payment.export'))$this->vm['links'][]=['label'=>'Xuất CSV theo bộ lọc','url'=>route('business.report.export',['context'=>'platform-admin']+$period->parameters())];
        $this->records($repository->query($this->user,$period),function($cycle)use($repository){return $this->card($cycle->bank_reference,$cycle->quote->accountSubscription->owner->name,['Ngày ghi nhận'=>$repository->date($cycle)->format('d/m/Y H:i:s'),'Gói'=>$cycle->quote->planVersion->snapshot['label'],'Số tiền'=>Vnd::format((string)$cycle->getRawOriginal('verified_amount')),'Người ghi nhận'=>User::find($cycle->verified_by)?->name??'Không còn hoạt động'],[],[$this->link('Chi tiết giao dịch','admin-dashboard',['section'=>'order','id'=>$cycle->subscription_quote_id])]);});
    }
}

