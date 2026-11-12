<?php
namespace App\Modules\Workspace\Models;
use App\Support\BusinessModel;
final class OwnerTransferCase extends BusinessModel {
    protected $table='owner_transfer_cases';
    protected function casts():array{return parent::casts()+['consents'=>'array','reviews'=>'array','blockers'=>'array','workspace_version'=>'integer','completed_at'=>'datetime','completion_snapshot'=>'array'];}
}
