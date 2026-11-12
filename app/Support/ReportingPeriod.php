<?php
namespace App\Support;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final readonly class ReportingPeriod
{
    public function __construct(public string $preset,public Carbon $from,public Carbon $until){}
    public static function request(Request $request):self
    {
        $d=$request->validate(['period'=>'nullable|in:today,week,month,year,custom','from'=>'required_if:period,custom|nullable|date_format:Y-m-d','until'=>'required_if:period,custom|nullable|date_format:Y-m-d']);
        $key=$d['period']??'month';$today=Carbon::now(config('platform_business.timezone'))->startOfDay();
        $from=match($key){'today'=>$today->copy(),'week'=>$today->copy()->subDays(6),'year'=>$today->copy()->startOfYear(),'custom'=>Carbon::createFromFormat('!Y-m-d',$d['from'],config('platform_business.timezone')),default=>$today->copy()->startOfMonth()};
        $until=$key==='custom'?Carbon::createFromFormat('!Y-m-d',$d['until'],config('platform_business.timezone')):$today->copy();
        abort_unless($until->gte($from)&&$from->diffInDays($until)<=3660,422,'Khoảng ngày không hợp lệ hoặc vượt quá 10 năm.');return new self($key,$from,$until);
    }
    public function bounds():array{return [$this->from->copy()->setTimezone(config('platform_business.storage_timezone'))->format('Y-m-d H:i:s'),$this->until->copy()->addDay()->setTimezone(config('platform_business.storage_timezone'))->format('Y-m-d H:i:s')];}
    public function parameters():array{return ['period'=>$this->preset,'from'=>$this->from->toDateString(),'until'=>$this->until->toDateString()];}
    public function bucket(string $at):string{return Carbon::parse($at,config('platform_business.storage_timezone'))->setTimezone(config('platform_business.timezone'))->format($this->from->diffInDays($this->until)>62?'Y-m':'Y-m-d');}
}
