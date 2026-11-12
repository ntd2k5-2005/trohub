<?php
namespace App\Support;
use Illuminate\Support\Facades\{DB,Schema};

/** Existing dataset registry is provenance, never an authorization source. */
final class SimulationOrigin
{
    public static function ids(string $table){return DB::table('demo_dataset_records')->where('table_name',$table)->select('record_id');}
    public static function contains(string $table,int|string $id):bool{return Schema::hasTable('demo_dataset_records')&&DB::table('demo_dataset_records')->where('table_name',$table)->where('record_id',(string)$id)->exists();}
    public static function dataset(string $table,int|string $id):?string{return Schema::hasTable('demo_dataset_records')?DB::table('demo_dataset_records')->where('table_name',$table)->where('record_id',(string)$id)->value('dataset'):null;}
    public static function filter($query,string $table,string $source):void
    {
        if(!Schema::hasTable('demo_dataset_records'))return;
        if($source==='simulation')$query->whereIn($table.'.id',self::ids($table));
        elseif($source==='operational')$query->whereNotIn($table.'.id',self::ids($table));
    }
    public static function inherit(string $from,int $fromId,string $to,int $toId):void
    {
        $dataset=self::dataset($from,$fromId);if(!$dataset)return;
        DB::table('demo_dataset_records')->insertOrIgnore(['dataset'=>$dataset,'table_name'=>$to,'record_id'=>(string)$toId,'demo_key'=>'interactive:'.$to.':'.$toId,'created_at'=>now()]);
    }
}
