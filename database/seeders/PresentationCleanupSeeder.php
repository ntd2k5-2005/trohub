<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PresentationCleanupSeeder extends Seeder
{
    public function run():void
    {
        if(!app()->environment(['local','testing']))throw new \RuntimeException('Sample presentation cleanup is restricted to local/testing');
        DB::transaction(function(){
            foreach(config('business_history.display_names')as$email=>[$old,$name])DB::table('users')->where('email',$email)->where('name',$old)->update(['name'=>$name,'updated_at'=>now()]);
            $properties=DB::table('demo_dataset_records')->where('dataset','TROHUB_DEMO_V1')->where('table_name','properties')->select('record_id');
            foreach(DB::table('properties')->whereIn('id',$properties)->get()as$p){$changes=[];foreach(['address','public_address','description']as$field){$value=$p->$field??null;if(!$value)continue;$clean=str_replace([' (địa chỉ minh họa)','Khu dân cư minh họa','dữ liệu trình diễn','Dữ liệu trình diễn'],['','Khu dân cư','thông tin nhà trọ','Thông tin nhà trọ'],$value);if($clean!==$value)$changes[$field]=$clean;}if($changes)DB::table('properties')->where('id',$p->id)->where('updated_at',$p->updated_at)->update($changes+['updated_at'=>now()]);}
        });
    }
}
