@php($business=$screen['business'])
@if(!empty($business['period']))
@include('product.dashboard-filter',['period'=>$business['period'],'scope'=>null])
@endif
@if(isset($business['received']))
<section class="app-card p-5"><h2 class="font-bold">Tiền gói đã ghi nhận trong kỳ</h2><p class="mt-2 text-3xl font-bold" data-received-vnd="{{ $business['received'] }}">{{ \App\Modules\Subscription\Vnd::format($business['received']) }}</p><p class="mt-2 text-sm text-slate-500">Theo ngày xác nhận thực nhận, múi giờ Việt Nam. Không gồm tiền thuê phòng, cọc hoặc báo giá chưa được xác nhận.</p></section>
<section class="grid gap-4 md:grid-cols-2" aria-label="Biểu đồ tiền gói">
    @foreach(['series'=>'Tiền gói theo thời gian','plans'=>'Cơ cấu tiền gói đã nhận'] as $key=>$heading)
    <div class="app-card min-w-0 p-5"><h2 class="font-semibold">{{ $heading }}</h2>@if(empty($business[$key]))<p class="mt-4 text-sm text-slate-500">Chưa có tiền gói được xác nhận trong khoảng đã chọn.</p>@else<div class="mt-4 space-y-3">@foreach($business[$key] as $label=>$amount)<div><div class="flex flex-wrap justify-between gap-2 text-sm"><span>{{ $label }}</span><span>{{ \App\Modules\Subscription\Vnd::format($amount) }}</span></div><meter class="mt-1 block w-full" min="0" max="{{ max(1,(float)$business['received']) }}" value="{{ $amount }}" aria-label="{{ $label }}"></meter></div>@endforeach</div>@endif</div>
    @endforeach
</section>
@endif
@if(!empty($business['subscriptions']))<section class="app-card p-5"><h2 class="font-semibold">Thuê bao hiện tại theo gói</h2><dl class="mt-4 grid gap-3 sm:grid-cols-3">@foreach($business['subscriptions'] as $label=>$count)<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-1 text-xl font-bold">{{ $count }}</dd></div>@endforeach</dl></section>@endif
