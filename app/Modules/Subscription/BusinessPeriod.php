<?php

namespace App\Modules\Subscription;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final readonly class BusinessPeriod
{
    public function __construct(public string $preset, public Carbon $from, public Carbon $until, public ?string $plan, public string $q,public string $source='all') {}
    public static function request(Request $request): self
    {
        $data=$request->validate(['plan'=>'nullable|in:monthly,yearly','q'=>'nullable|string|max:150','source'=>'nullable|in:all,operational,simulation']);
        $period=\App\Support\ReportingPeriod::request($request);
        return new self($period->preset,$period->from,$period->until,$data['plan']??null,$data['q']??'',$data['source']??'all');
    }
    public function bounds(): array
    {
        $zone=config('platform_business.storage_timezone');
        return [$this->from->copy()->setTimezone($zone)->format('Y-m-d H:i:s'),$this->until->copy()->addDay()->setTimezone($zone)->format('Y-m-d H:i:s')];
    }
    public function parameters(): array { return ['period'=>$this->preset,'from'=>$this->from->toDateString(),'until'=>$this->until->toDateString(),'plan'=>$this->plan,'q'=>$this->q,'source'=>$this->source]; }
}
