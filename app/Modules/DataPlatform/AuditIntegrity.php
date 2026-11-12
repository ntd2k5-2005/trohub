<?php
namespace App\Modules\DataPlatform;
use App\Modules\DataPlatform\Models\AuditEntry;
use Illuminate\Support\Facades\DB;
/** Integrity evidence against accidental edits/deletions; not proof against a DBA holding the application key. */
final class AuditIntegrity
{
    private function canonical(mixed $v):mixed
    {
        if(!is_array($v))return $v;
        if(!array_is_list($v))ksort($v);
        foreach($v as$k=>$item)$v[$k]=$this->canonical($item);
        return $v;
    }
    private function digest(array $data):string{return hash_hmac('sha256',json_encode($this->canonical($data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(string)config('app.key'));}
    private function payload(object $row):array
    {
        return ['id'=>(int)$row->id,'actor_id'=>$row->actor_id===null?null:(int)$row->actor_id,'workspace_id'=>$row->workspace_id===null?null:(int)$row->workspace_id,'action'=>$row->action,'resource_type'=>$row->resource_type,'resource_id'=>(int)$row->resource_id,'changes'=>is_string($row->changes)?json_decode($row->changes,true,512,JSON_THROW_ON_ERROR):$row->changes,'created_at'=>(string)$row->created_at,'updated_at'=>(string)$row->updated_at];
    }
    public function legacyDigest():string
    {
        $hash=hash_init('sha256',HASH_HMAC,(string)config('app.key'));
        foreach(DB::table('audit_entries')->whereNull('integrity_sequence')->orderBy('id')->cursor()as$row)hash_update($hash,$this->digest($this->payload($row)));
        return hash_final($hash);
    }
    public function append(AuditEntry $entry):void
    {
        $head=DB::table('audit_integrity_head')->where('id',1)->lockForUpdate()->first();
        if(!$head)throw new \RuntimeException('Thiếu mốc kiểm định nhật ký.');
        // The model is inserted while its save transaction still owns this head lock.
        $row=DB::table('audit_entries')->where('id',$entry->id)->first();$sequence=(int)$head->sequence+1;
        $hash=$this->digest(['sequence'=>$sequence,'previous'=>$head->last_hash,'entry'=>$this->payload($row)]);
        DB::table('audit_entries')->where('id',$entry->id)->update(['integrity_sequence'=>$sequence,'previous_hash'=>$head->last_hash,'integrity_hash'=>$hash]);
        DB::table('audit_integrity_head')->where('id',1)->update(['sequence'=>$sequence,'last_hash'=>$hash]);
        $entry->forceFill(['integrity_sequence'=>$sequence,'previous_hash'=>$head->last_hash,'integrity_hash'=>$hash])->syncOriginal();
    }
    public function verify():array
    {
        return DB::transaction(function(){
            $head=DB::table('audit_integrity_head')->where('id',1)->lockForUpdate()->first();
            if(!$head)return ['valid'=>false,'reason'=>'missing_head'];
            $previous=$this->legacyDigest();$sequence=0;
            if(!hash_equals($head->legacy_digest,$previous))return ['valid'=>false,'reason'=>'legacy_changed'];
            foreach(DB::table('audit_entries')->whereNotNull('integrity_sequence')->orderBy('integrity_sequence')->cursor()as$row){
                $sequence++;
                $hash=$this->digest(['sequence'=>$sequence,'previous'=>$previous,'entry'=>$this->payload($row)]);
                if((int)$row->integrity_sequence!==$sequence||!hash_equals($previous,$row->previous_hash??'')||!hash_equals($hash,$row->integrity_hash??''))return ['valid'=>false,'reason'=>'entry_changed_or_missing','sequence'=>$sequence];
                $previous=$hash;
            }
            return ['valid'=>$sequence===(int)$head->sequence&&hash_equals($previous,$head->last_hash),'sequence'=>$sequence,'legacy_baseline'=>'observed_at_migration'];
        });
    }
}
