<?php
namespace App\Modules\Subscription\Models;
use App\Support\BusinessModel;
final class PlanVersion extends BusinessModel
{
    protected $table='plan_versions';
    protected function casts(): array{return parent::casts()+['published_at'=>'datetime','predecessor_id'=>'integer'];}
    protected static function booted(): void
    {
        static::updating(function($p){
            abort_if($p->isDirty(['plan','predecessor_id']),409,'Không thể đổi loại gói hoặc nguồn phiên bản.');
            $published=$p->getOriginal('published_at')||static::whereKey($p->id)->whereNotNull('published_at')->exists();
            abort_if($published&&array_diff(array_keys($p->getDirty()),['availability','updated_at']),409,'Giá, thời hạn và quyền lợi của phiên bản đã phát hành không được sửa.');
            if($p->isDirty(['snapshot','fingerprint'])) abort_if(SubscriptionQuote::where('plan_version_id',$p->id)->exists()||AccountSubscription::where('plan_version_id',$p->id)->exists(),409,'Phiên bản đã được sử dụng không thể chỉnh sửa.');
        });
        static::deleting(fn()=>abort(409));
    }
}
