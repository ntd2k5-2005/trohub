<?php
namespace App\Modules\AccessControl;
use App\Models\User;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\Workspace\Models\Workspace;
/** Read-only evidence classification; never repairs ancestry or changes an authority source. */
final class GrantExplanation
{
    public function classification(DirectGrant$g):string
    {
        if($g->authority_kind==='legacy')return 'AMBIGUOUS';
        if(!$g->source_kind||!$g->source_id||!$g->source_snapshot||!$g->delegation_source_kind||!$g->delegation_source_id||!$g->delegation_snapshot)return 'AMBIGUOUS';
        if(!$g->workspace_id&&in_array($g->scope_type,['platform','personal'],true))return 'GLOBAL_NATIVE';
        if($g->authority_kind==='organization'&&Workspace::whereKey($g->workspace_id)->where('owner_id',$g->grantor_id)->exists())return 'ORGANIZATIONAL';
        if($g->authority_kind==='personal'&&$g->issuer_membership_id)return 'PERSONAL_DELEGATION';
        return 'AMBIGUOUS';
    }
    public function details(DirectGrant$g):array
    {
        $kind=$this->classification($g);$graph=app(GrantGraph::class)->usable($g);
        $w=$g->workspace_id?Workspace::find($g->workspace_id):null;$u=User::find($g->user_id);
        $effective=$u&&($w?app(AccessService::class)->allowed($u,$g->permission,$w,$g->property_id,$g->room_id):app(AccessService::class)->personal($u,$g->permission)||app(AccessService::class)->global($u,$g->permission));
        $reason=match(true){$g->revoked_at!==null=>'Quyền đã bị thu hồi.', $g->valid_until?->lte(now())??false=>'Đã hết thời hạn.', $g->valid_from?->gt(now())??false=>'Chưa đến thời điểm sử dụng.', !$graph=>'Nguồn cấp hoặc lần tham gia không còn đủ điều kiện.', !$effective=>'Tài khoản, phạm vi hoặc dịch vụ đang hạn chế thao tác này.', default=>'Có thể sử dụng trong phạm vi được cấp.'};
        return ['Phân loại nguồn'=>['AMBIGUOUS'=>'Quyền cũ chưa đủ căn cứ đối chiếu','GLOBAL_NATIVE'=>'Cấp cho tài khoản','ORGANIZATIONAL'=>'Cấp bởi chủ khu quản lý','PERSONAL_DELEGATION'=>'Được ủy quyền từ nhân sự'][$kind],'Hiệu lực hiện tại'=>$reason,'Cấp tiếp từ quyền này'=>$kind==='AMBIGUOUS'?'Chưa đủ căn cứ để cấp tiếp':'Phải kiểm tra điều kiện ủy quyền tại thời điểm cấp'];
    }
    public function report():array
    {
        $rows=DirectGrant::orderBy('id')->get()->map(fn($g)=>['grant_id'=>$g->id,'classification'=>$this->classification($g),'revoked'=>$g->revoked_at!==null,'expired'=>$g->valid_until?->lte(now())??false,'source_kind'=>$g->source_kind,'source_id'=>$g->source_id,'unchanged'=>true])->all();
        return ['read_only'=>true,'counts'=>array_count_values(array_column($rows,'classification')),'grants'=>$rows];
    }
}
