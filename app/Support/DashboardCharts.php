<?php
namespace App\Support;
use Illuminate\Support\Carbon;

final class DashboardCharts
{
    public static function time(string $title,array $series):array
    {
        return ['type'=>'columns','title'=>$title,'unit'=>'money','labels'=>array_map(fn($key)=>Carbon::parse(strlen($key)===7?$key.'-01':$key)->format(strlen($key)===7?'m/Y':'d/m'),array_keys($series)),'series'=>[['label'=>'Đã ghi nhận','values'=>array_values($series)]]];
    }
    public static function distribution(string $title,array $values,string $unit='tài khoản'):array{return ['type'=>'donut','title'=>$title,'unit'=>'count','count_unit'=>$unit,'labels'=>array_keys($values),'series'=>[['label'=>$unit,'values'=>array_values($values)]]];}
    public static function horizontal(string $title,array $values,array $links=[]):array{return ['type'=>'horizontal','title'=>$title,'unit'=>'count','labels'=>array_keys($values),'series'=>[['label'=>'Hồ sơ','values'=>array_values($values)]],'links'=>$links];}
}
