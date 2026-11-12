@php($detail=$screen['record_detail'])
@php($record=$detail['record'])
<div class="space-y-6" data-record-detail>
    <header><a class="inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-indigo-600" href="{{ $detail['back_url'] }}"><span aria-hidden="true">←</span> Trở về {{ mb_strtolower($detail['title']) }}</a><p class="mb-2 mt-5 text-sm text-slate-500">{{ $contexts[$context]['label'] }}</p><h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $record['_primary'] }}</h1></header>
    @if(session('status'))<p role="status" class="app-card p-4 text-emerald-700 dark:text-emerald-300">{{ session('status') }}</p>@endif
    @if($errors->any())<div role="alert" class="app-card p-4 text-rose-700 dark:text-rose-300"><p class="font-semibold">Vui lòng kiểm tra lại thông tin.</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(!empty($record['images']))<section class="app-card p-4"><div class="grid gap-3 sm:grid-cols-2">@foreach(array_slice($record['images'],0,4) as $src)<img class="aspect-[16/10] w-full rounded-xl object-cover" src="{{ $src }}" alt="{{ $record['title'] }}" referrerpolicy="no-referrer" x-data x-on:error.once="$el.src=@js(config('housing.fallback_image'))">@endforeach</div></section>@endif
    @if(isset($record['payment_request']))
        @include('product.payment-request',['paymentRequest'=>$record['payment_request']])
    @elseif(isset($screen['people']))
        @include('product.people-record')
    @else
        @include('product.record')
    @endif
    @if(!empty($record['description']))<section class="app-card whitespace-pre-line p-5 text-sm leading-7">{{ $record['description'] }}</section>@endif
</div>
