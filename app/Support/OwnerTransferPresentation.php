<?php
namespace App\Support;
use App\Models\User;
use App\Modules\Workspace\OwnerTransferService;
use App\Modules\Workspace\Models\{OwnerTransferCase,OwnerTransferEvidence,Workspace};
trait OwnerTransferPresentation
{
    protected function transferCases():void
    {
        $cases = app(OwnerTransferService::class)->visible($this->user)->latest()->paginate(config('business.pagination'), ['*'], 'transfers_page')->withQueryString();
        $this->vm['pagination'] = $cases;
        foreach($cases as$c){
            $forms=[];$participant=in_array($this->user->id,[$c->source_owner_id,$c->recipient_id],true);$open=!in_array($c->status,['rejected','cancelled','completed'],true)&&$c->expires_at->isFuture();
            if($open&&$participant){
                $forms[]=$this->form($this->user->id===$c->recipient_id?'Đồng ý nhận quyền quản lý':'Xác nhận lại đề nghị bàn giao','transfer.consent',[$this->field('version','Phiên bản','hidden',value:$c->version),$this->field('password','Mật khẩu hiện tại','password'),$this->field('confirm','Tôi đã xem hồ sơ và xác nhận đề nghị này','checkbox')],$c->id);
                $forms[]=$this->form('Bổ sung chứng cứ','transfer.evidence',[$this->field('version','Phiên bản','hidden',value:$c->version),$this->field('evidence','Tài liệu PDF hoặc hình ảnh','file')+['accept'=>'application/pdf,image/jpeg,image/png,image/webp']],$c->id);
                if($this->user->id===$c->source_owner_id)$forms[]=$this->form('Hủy đề nghị','transfer.cancel',[$this->field('version','Phiên bản','hidden',value:$c->version),$this->field('reason','Lý do','textarea')],$c->id);
            }
            if($open&&!$participant&&$this->access->global($this->user,'workspace.transfer.review'))$forms[]=$this->form('Ghi nhận kết quả thẩm tra','transfer.review',[$this->field('version','Phiên bản','hidden',value:$c->version),$this->field('decision','Kết quả','select',['review'=>'Ghi nhận điều kiện cần xử lý','reject'=>'Từ chối hồ sơ']),$this->field('note','Căn cứ và kết quả kiểm tra','textarea')],$c->id);
            $links=[];foreach(OwnerTransferEvidence::where('owner_transfer_case_id',$c->id)->orderBy('version')->get()as$e)$links[]=['label'=>'Tài liệu '.$e->version.': '.$e->filename,'url'=>route('ownership.evidence',['case'=>$c->id,'evidence'=>$e->id])];
            $this->vm['records'][]=$this->card('Bàn giao: '.Workspace::findOrFail($c->workspace_id)->name,'Hồ sơ chuyển quyền quản lý',[
                'Bên đề nghị'=>User::find($c->source_owner_id)?->name??'Tài khoản không còn hoạt động','Người nhận'=>User::find($c->recipient_id)?->name??'Tài khoản không còn hoạt động',
                'Trạng thái'=>['pending_consent'=>'Chờ hai bên xác nhận','pending_review'=>'Chờ thẩm tra','blocked'=>'Cần xử lý điều kiện','rejected'=>'Đã từ chối','cancelled'=>'Đã hủy','completed'=>'Đã hoàn tất'][$c->status],
                'Lý do'=>$c->reason,'Xác nhận'=>count($c->consents).' / 2','Hạn xác nhận'=>$c->expires_at->format('d/m/Y H:i'),'Hoàn tất lúc'=>$c->completed_at?->format('d/m/Y H:i')??'Chưa hoàn tất','Điều kiện còn thiếu'=>implode(' ',$c->blockers)?:($c->status==='completed'?'Đã bàn giao quyền quản lý.':'Quyền quản lý được bàn giao khi hai bên đã xác nhận và khu đáp ứng điều kiện vận hành.'),
            ],$forms,$links) + ['_key'=>'owner_transfer_cases:'.$c->id];
        }
    }
}
