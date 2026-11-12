<?php

namespace App\Modules\DataPlatform\Models;

use App\Support\BusinessModel;

class AuditEntry extends BusinessModel
{
    protected $table = 'audit_entries';

    public function save(array $options = [])
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('audit_integrity_head'))return parent::save($options);
        if($this->exists)throw new \LogicException('Nhật ký đã ghi không được chỉnh sửa.');
        return \App\Support\SqlTransactions::run(function()use($options){
            // Serialize the chain before inserting, including standalone writes.
            \Illuminate\Support\Facades\DB::table('audit_integrity_head')->where('id',1)->lockForUpdate()->first();
            $saved=parent::save($options);
            app(\App\Modules\DataPlatform\AuditIntegrity::class)->append($this);
            return $saved;
        });
    }

    public function delete(){throw new \LogicException('Không xóa nhật ký nghiệp vụ.');}
}
