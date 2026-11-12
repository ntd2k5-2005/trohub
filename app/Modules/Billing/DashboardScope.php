<?php
namespace App\Modules\Billing;
use App\Contracts\Authorization;
use App\Models\User;
use Illuminate\Http\Request;

final class DashboardScope
{
    public function __construct(private Authorization $access){}
    public function available(User $user)
    {
        return $this->access->workspaces($user)->filter(fn($w)=>$this->financial($user,$w))->values();
    }
    public function financial(User $user,$workspace):bool
    {
        foreach(['invoice.view','payment.view']as$p)if($this->access->allowed($user,$p,$workspace)||$this->access->roomIds($user,$workspace,$p)!==[])return true;return false;
    }
    public function resolve(User $user,Request $request):array
    {
        abort_unless($user->status==='active'&&$request->attributes->get('ui.context')==='workspace',403);
        $d=$request->validate(['workspace_scope'=>'nullable|in:current,all,selected','workspace_ids'=>'nullable|array|max:'.config('dashboards.max_selected_workspaces'),'workspace_ids.*'=>'integer|min:1|distinct']);
        $available=$this->available($user);$allowed=$available->pluck('id')->all();$provided=array_map('intval',$d['workspace_ids']??[]);
        abort_if(array_diff($provided,$allowed),403,'Bạn không có quyền xem báo cáo của khu đã chọn.');
        $mode=$d['workspace_scope']??'current';$ids=match($mode){'all'=>$allowed,'selected'=>$provided,default=>[$request->attributes->get('workspace')?->id]};
        abort_unless($ids&&count($ids)===count(array_unique($ids))&&!array_diff($ids,$allowed),403,'Chọn khu có quyền xem dữ liệu kinh doanh.');
        $selected=$available->whereIn('id',$ids)->values();$rooms=[];$capabilities=[];
        foreach(['payment.view','invoice.view','property.view','rental.view','maintenance.view']as$permission){$rooms[$permission]=[];$capabilities[$permission]=false;
            foreach($selected as$w){$list=$this->access->roomIds($user,$w,$permission);$rooms[$permission]=array_merge($rooms[$permission],$list);$capabilities[$permission]=$capabilities[$permission]||$list!==[]||$this->access->allowed($user,$permission,$w);}
            $rooms[$permission]=array_values(array_unique($rooms[$permission]));
        }
        return ['available'=>$available,'selected'=>$selected,'mode'=>$mode,'ids'=>$ids,'rooms'=>$rooms,'capabilities'=>$capabilities];
    }
}
