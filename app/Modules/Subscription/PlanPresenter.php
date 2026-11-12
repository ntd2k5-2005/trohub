<?php
namespace App\Modules\Subscription;
use App\Models\User;
use App\Modules\Subscription\Models\PlanVersion;
use App\Support\ProductPresenter;
final class PlanPresenter extends ProductPresenter
{
    protected function build(string $slug,string $q): void
    {
        app(BusinessAccess::class)->authorize($this->user,'plans.view');
        $this->vm['notice']='Phiên bản mới áp dụng khi được phát hành và lựa chọn cho kỳ gói hoặc hạn mức mới. Gói dịch vụ hiện có giữ phiên bản đã xác lập.';
        $fields=[$this->field('plan','Loại gói','select',collect(config('subscriptions.plans'))->mapWithKeys(fn($p,$k)=>[$k=>$p['label']])->all()),$this->field('label','Tên hiển thị'),$this->field('price','Giá gói (đồng)','number',required:false),$this->field('duration_count','Thời hạn','number'),$this->field('duration_unit','Đơn vị thời hạn','select',['days'=>'Ngày','months'=>'Tháng','years'=>'Năm']),$this->field('features','Tính năng','choices',config('subscriptions.feature_labels'),[],false)];
        foreach(['workspaces'=>'Khu quản lý','properties'=>'Nhà trọ','rooms'=>'Phòng','staff'=>'Nhân sự'] as $key=>$label){$fields[]=$this->field($key.'_mode','Hạn mức '.$label,'select',['unspecified'=>'Chưa cấu hình','limited'=>'Giới hạn bằng số','unlimited'=>'Không giới hạn'],'unspecified');$fields[]=$this->field('max_'.$key,'Số lượng '.$label.' (khi chọn giới hạn)','number',required:false);}
        $fields[]=$this->field('reason','Lý do tạo phiên bản','textarea');$this->vm['forms'][]=$this->form('Soạn phiên bản gói','plan.save',$fields);
        $options=[];
        $query=PlanVersion::latest('id');if(preg_match('/^plan_versions:([1-9][0-9]*)$/',$this->request->query('item',''),$match))$query->whereKey($match[1]);$pager=$query->paginate(config('business.pagination'),['*'],'page',$this->request->filled('item')?1:null)->withQueryString();$this->vm['pagination']=$pager;
        foreach($pager as $p){
            $simulation=\App\Support\SimulationOrigin::contains('plan_versions',$p->id);
            $states=['public'=>'Mở đăng ký','renewal_only'=>'Chỉ gia hạn','closed'=>'Ngừng nhận yêu cầu','archived'=>'Đã lưu trữ'];
            $facts=['Trạng thái'=>$p->published_at?'Đã phát hành':'Bản soạn','Giá'=>$p->snapshot['price']===null?'Chưa cấu hình':Vnd::format((string)$p->snapshot['price']),'Thời hạn'=>$p->snapshot['duration_count'].' '.['days'=>'ngày','months'=>'tháng','years'=>'năm'][$p->snapshot['duration_unit']],'Tính năng'=>collect(config('subscriptions.feature_labels'))->only($p->snapshot['features'])->implode(', ')?:'Chưa chọn'];
            foreach(['workspaces'=>'Khu quản lý','properties'=>'Nhà trọ','rooms'=>'Phòng','staff'=>'Nhân sự'] as $key=>$label){$value=$p->snapshot['limits'][$key]??null;$facts[$label]=$value===null?'Chưa cấu hình':($value==='unlimited'?'Không giới hạn':number_format($value));}
            if($p->published_at){$facts['Tiếp nhận yêu cầu']=$states[$p->availability];$facts['Ngày phát hành']=$p->published_at->format('d/m/Y H:i');}
            $facts['Phiên bản']=$p->id;
            if($simulation){$facts['Trạng thái']='Đã lưu trữ';$facts['Tiếp nhận yêu cầu']='Ngừng tiếp nhận';}
            $forms=$p->published_at?[$this->form('Thay đổi tiếp nhận yêu cầu','plan.availability',[$this->field('expected','Trạng thái trước','hidden',value:$p->availability),$this->field('availability','Tiếp nhận yêu cầu','select',$states,$p->availability),$this->field('reason','Lý do','textarea')],$p->id)]:[$this->form('Phát hành phiên bản','plan.publish',[$this->field('confirm','Tôi xác nhận giá, thời hạn, tính năng và toàn bộ hạn mức','checkbox')],$p->id)];
            if(!$p->published_at){
                $edit=$fields;
                foreach($edit as &$field){$key=$field['name'];
                    if($key==='plan'){$field['type']='hidden';$field['value']=$p->plan;}
                    elseif($key==='reason')$field['value']='';
                    elseif(str_ends_with($key,'_mode')){$limit=$p->snapshot['limits'][substr($key,0,-5)]??null;$field['value']=$limit===null?'unspecified':($limit==='unlimited'?'unlimited':'limited');}
                    elseif(str_starts_with($key,'max_')){$limit=$p->snapshot['limits'][substr($key,4)]??null;$field['value']=is_int($limit)?$limit:null;}
                    else $field['value']=$p->snapshot[$key]??null;
                }unset($field);
                $edit[]=$this->field('expected_fingerprint','','hidden',value:$p->fingerprint);
                array_unshift($forms,$this->form('Sửa bản nháp','plan.save',$edit,$p->id));
            }
            $this->vm['records'][]=$this->card($p->snapshot['label'],'Gói dịch vụ',$facts,$simulation?[]:$forms)+['_key'=>'plan_versions:'.$p->id];
            if($p->published_at&&!$simulation)$options[$p->id]=$p->snapshot['label'].' · '.($p->published_at->format('d/m/Y H:i'));
        }
        if($options)$this->vm['forms'][]=$this->form('Áp dụng hạn mức cho tài khoản dùng gói cũ','plan.quota.assign',[$this->field('owner_id','Chủ khu quản lý','select',User::whereIn('id',\App\Modules\Workspace\Models\Workspace::select('owner_id'))->pluck('name','id')->all()),$this->field('plan_version_id','Phiên bản hạn mức','select',$options),$this->field('reason','Căn cứ áp dụng','textarea'),$this->field('confirm','Tôi xác nhận chỉ thay hạn mức cho thao tác tiếp theo, không đổi kỳ gói hoặc tiền','checkbox')]);
    }
}
