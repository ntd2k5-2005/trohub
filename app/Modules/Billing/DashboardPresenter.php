<?php
namespace App\Modules\Billing;
use App\Support\{DashboardCharts,ProductPresenter,ReportingPeriod,Vnd};

final class DashboardPresenter extends ProductPresenter
{
    protected function build(string $slug,string $q):void
    {
        $scope=app(DashboardScope::class)->resolve($this->user,$this->request);$period=ReportingPeriod::request($this->request);$data=app(DashboardRepository::class)->summarize($scope,$period);$can=$scope['capabilities'];
        $kpis=[['label'=>'Tiền hóa đơn đã thu trong kỳ','value'=>$can['payment.view']?Vnd::format($data['received']):'Chưa có quyền xem','note'=>'Theo ngày ghi nhận khoản thu'],['label'=>'Công nợ hiện tại','value'=>$can['invoice.view']?Vnd::format($data['outstanding']):'Chưa có quyền xem','note'=>'Các hóa đơn đã phát hành còn phải thu'],['label'=>'Phòng đang cho thuê','value'=>$can['property.view']?$data['occupied'].' / '.$data['exploited_units']:'Chưa có quyền xem','note'=>$can['property.view']?'Lấp đầy '.$data['occupancy'].'% · không gồm phòng tạm ngừng':''],['label'=>'Hóa đơn quá hạn','value'=>$can['invoice.view']?$data['overdue']:'Chưa có quyền xem','note'=>$can['invoice.view']?'Còn '.Vnd::format($data['overdue_amount']).' quá hạn':'']];
        $charts=[];if($can['payment.view'])$charts[]=DashboardCharts::time('Tiền hóa đơn đã thu theo thời gian',$data['series']);
        if($can['payment.view']||$can['invoice.view'])$charts[]=['type'=>'columns','title'=>'Đã thu trong kỳ và công nợ hiện tại theo khu','unit'=>'money','labels'=>array_column($data['workspaces'],'label'),'series'=>array_values(array_filter([$can['payment.view']?['label'=>'Đã thu trong kỳ','values'=>array_column($data['workspaces'],'received')]:null,$can['invoice.view']?['label'=>'Còn phải thu hiện tại','values'=>array_column($data['workspaces'],'outstanding')]:null]))];
        if($can['property.view'])$charts[]=DashboardCharts::distribution('Tình trạng khai thác phòng',['Đang cho thuê'=>$data['room_states']['occupied'],'Còn trống'=>$data['room_states']['vacant'],'Đang giữ'=>$data['room_states']['reserved'],'Tạm ngừng'=>$data['room_states']['inactive']],'phòng');
        $lists=[];foreach($data['worklists']as$title=>$items){$permission=match($title){'Hóa đơn quá hạn','Hóa đơn chờ ghi nhận thanh toán'=>'invoice.view','Hợp đồng cần gia hạn'=>'rental.view',default=>'property.view'};if(!$can[$permission])continue;
            foreach($items as&$item){$params=['workspace_id'=>$item['workspace_id']];$slug=match($item['kind']){'invoice'=>'billing','rental'=>'rental-detail',default=>'room-detail'};$params[$item['kind']==='invoice'?'invoice_id':'id']=$item['id'];$item['url']=$this->link('Xem hồ sơ',$slug,$params)['url'];}unset($item);$lists[]=['title'=>$title,'items'=>$items];}
        $this->vm['dashboard']=['kind'=>'landlord','period'=>$period->parameters(),'scope'=>['mode'=>$scope['mode'],'ids'=>$scope['ids'],'options'=>$scope['available']->pluck('name','id')->all(),'names'=>$scope['selected']->pluck('name')->implode(', ')],'kpis'=>$kpis,'charts'=>$charts,'worklists'=>$lists,'simulation'=>$data['simulation_received']!=='0','simulation_received'=>$data['simulation_received'],'metrics'=>$data,'detail'=>[['label'=>'Hóa đơn thanh toán một phần','value'=>$data['partial_invoices']],['label'=>'Hóa đơn đã phát hành','value'=>$data['invoice_count']]],'links'=>[$this->link('Tổng quan vận hành','workspace-dashboard',['tab'=>'operations'])],'quality_warning'=>$data['mismatched_paid_flags']?'Có '.$data['mismatched_paid_flags'].' hóa đơn cần đối chiếu trạng thái với các khoản thu.':null];
    }
}
