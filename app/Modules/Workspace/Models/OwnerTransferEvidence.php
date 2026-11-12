<?php
namespace App\Modules\Workspace\Models;
use App\Support\BusinessModel;
final class OwnerTransferEvidence extends BusinessModel {
    protected $table='owner_transfer_evidence';protected $hidden=['path','checksum'];
    protected static function booted():void{static::updating(fn()=>abort(409,'Hãy bổ sung phiên bản chứng cứ mới, không ghi lại hồ sơ cũ.'));static::deleting(fn()=>abort(409));}
}
