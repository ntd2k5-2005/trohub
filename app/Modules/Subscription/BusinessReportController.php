<?php

namespace App\Modules\Subscription;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

final class BusinessReportController extends Controller
{
    public function __invoke(Request $request, RevenueRepository $repository)
    {
        abort_unless($request->attributes->get('ui.context')==='platform-admin'&&!$request->attributes->get('workspace'),403);
        $authorize=function()use($request){$actor=User::findOrFail($request->user()->id);app(BusinessAccess::class)->authorize($actor,'subscription.revenue.view');app(BusinessAccess::class)->authorize($actor,'payment.export');};
        $authorize();$period=BusinessPeriod::request($request);
        return response()->streamDownload(function()use($request,$repository,$period,$authorize){
            $authorize();$stream=fopen('php://output','w');fwrite($stream,"\xEF\xBB\xBF");
            fputcsv($stream,['Ngày xác nhận','Mã giao dịch','Người thanh toán','Gói','Số tiền VND','Người xác nhận' ],',','"','');
            foreach($repository->query($request->user(),$period)->orderBy('verified_at')->orderBy('id')->cursor()as$cycle){
                $authorize();$cells=[$repository->date($cycle)->format('d/m/Y H:i:s'),$cycle->bank_reference,$cycle->quote->accountSubscription->owner->name,$cycle->quote->planVersion->snapshot['label'],(string)$cycle->getRawOriginal('verified_amount'),User::find($cycle->verified_by)?->name??'Không còn hoạt động'];
                fputcsv($stream,array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v,$cells),',','"','');
            }
            fclose($stream);
        },'tien-goi-da-nhan-'.$period->from->format('Ymd').'-'.$period->until->format('Ymd').'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
