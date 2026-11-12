<?php
namespace App\Support;

use Illuminate\Http\Request;

/** List/detail presentation only; every row is supplied by an already authorized presenter. */
final class RecordPresentation
{
    public static function specification(Request $request,string $slug):?array
    {
        $spec=config('presentation.lists.'.$slug);
        if(!$spec||isset($spec['sections'])&&!in_array($request->input('section'),$spec['sections'],true))return null;
        return array_replace($spec,config('presentation.sections.'.$slug.'.'.$request->input('section'),[]),config('presentation.variants.'.$slug.'.'.$request->input('section').'.'.$request->input('tab'),[]));
    }
    public static function key(array $record):string
    {
        return $record['_key']??'row:'.substr(hash('sha256',json_encode([$record['title'],$record['subtitle'],$record['facts']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),0,32);
    }
    public function present(array $screen,Request $request,string $slug):array
    {
        $spec=self::specification($request,$slug);if(!$spec)return $screen;
        $main=$this->table($screen['records'],$request,$spec,!empty($screen['_record_sortable']));$main['total']=$screen['pagination']?->total()??count($main['records']);$screen['records']=$main['records'];$all=$main['records'];
        if(isset($screen['rental_requests'])){$screen['request_table']=$this->table($screen['rental_requests'],$request,['title'=>'Yêu cầu thuê và giữ phòng','columns'=>['Trạng thái','Giá đề nghị','Tiền cọc','Ngày gửi']],false);$screen['request_table']['total']=$screen['request_pagination']?->total()??count($screen['rental_requests']);$all=[...$all,...$screen['request_table']['records']];}
        if(isset($screen['standalone_payment_requests'])){
            $rows=[];foreach($screen['standalone_payment_requests']as$p)$rows[]=['_key'=>'payment_requests:'.$p->id,'title'=>'Đặt cọc · '.$p->room_label,'subtitle'=>$p->history_only?'Khoản thu đã xác nhận':($p->transfer_reference??'Khoản đặt cọc'),'facts'=>['Trạng thái'=>$p->history_only?'Đã có khoản thu':$p->status_label,'Số tiền'=>Vnd::format((string)$p->amount)]+($p->history_only?[]:['Còn phải thu'=>Vnd::format((string)$p->remaining)]),'forms'=>[],'links'=>[],'payment_request'=>$p];
            $screen['deposit_table']=$this->table($rows,$request,['title'=>'Các khoản đặt cọc','columns'=>['Trạng thái','Số tiền','Còn phải thu']],false);$screen['deposit_table']['total']=$screen['deposit_pagination']?->total()??count($rows);$all=[...$all,...$screen['deposit_table']['records']];
        }
        if(isset($screen['subscription_payments'])){
            $rows=[];foreach($screen['subscription_payments']as$p){$quote=$p['quote'];$state=$quote->status==='cancelled'?'Đã hủy':($quote->status==='confirmed'?'Đã thanh toán':($quote->expires_at->isPast()?'Đã quá hạn':($quote->payer_reported_at?'Chờ xác nhận':'Chờ thanh toán')));$rows[]=['_key'=>'checkout:'.$quote->id,'title'=>$quote->planVersion->snapshot['label'],'subtitle'=>$quote->reference,'facts'=>['Trạng thái'=>$state,'Số tiền'=>Vnd::format((string)$quote->amount),'Hạn thanh toán'=>$quote->expires_at->format('d/m/Y H:i')],'forms'=>[],'links'=>[],'_detail_url'=>$request->fullUrlWithQuery(['payment_id'=>$quote->id])];}
            $screen['payment_table']=$this->table($rows,$request,['title'=>'Thanh toán gói dịch vụ','columns'=>['Trạng thái','Số tiền','Hạn thanh toán']],false);
        }
        if($request->filled('item')){
            $selected=array_values(array_filter($all,fn($r)=>hash_equals($r['_key'],$request->query('item'))));
            abort_unless(count($selected)===1,404);
            $screen['record_detail']=['record'=>$selected[0],'title'=>$spec['title'],'back_url'=>$request->fullUrlWithoutQuery(['item'])];
            $screen['forms']=[];$screen['stats']=[];$screen['pagination']=null;
        }else $screen['record_table']=$main;
        return $screen;
    }
    private function table(array $rows,Request $request,array $spec,bool $sortable):array
    {
        $columns=$spec['columns'];$records=[];$available=[];
        foreach($rows as$record){
            if(isset($record['price']))$record['facts']+=['Giá thuê'=>$record['price']];
            if(isset($record['area']))$record['facts']+=['Diện tích'=>number_format((float)$record['area'],2,',','.').' m²'];
            foreach($record['facts']as$label=>$value)if(is_string($value))$record['facts'][$label]=config('presentation.copy')[$value]??$value;
            $record['_key']=self::key($record);$record['_detail_url']??=$request->fullUrlWithQuery(['item'=>$record['_key']]);
            foreach($record['links']as$link)if(in_array($link['label'],config('presentation.canonical_details'),true)){$record['_detail_url']=$link['url'];break;}
            $record['_table_action']=collect($record['links'])->first(fn($link)=>!in_array($link['label'],config('presentation.canonical_details'),true))??['label'=>'Chi tiết','url'=>$record['_detail_url']];
            $record['_primary']=$record['facts'][$spec['primary_fact']??'']??$record['title'];
            $available=array_values(array_unique([...$available,...array_keys($record['facts'])]));$records[]=$record;
        }
        $columns=array_values(array_filter($columns,fn($col)=>in_array($col,$available,true)||$col==='Trạng thái'&&collect($records)->contains(fn($r)=>isset($r['state_label'])||isset($r['badge']))));
        if(!$columns)$columns=array_slice($available,0,config('presentation.column_limit'));
        return ['title'=>$spec['title'],'columns'=>$columns,'records'=>$records,'sortable'=>$sortable,'total'=>count($records)];
    }
}
