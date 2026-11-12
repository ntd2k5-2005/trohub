@extends('layouts.app')
@section('content')
@if(isset($screen['record_detail']))
@include('product.record-detail')
@elseif(isset($screen['subscription_payment_detail']))
<div class="space-y-6"><a class="text-sm font-medium text-slate-500" href="{{ request()->fullUrlWithoutQuery(['payment_id']) }}">← Trở về gói dịch vụ</a><h1 class="text-2xl font-bold">Thanh toán gói dịch vụ</h1>@if(session('status'))<p class="app-card p-4" role="status">{{ session('status') }}</p>@endif @if($errors->any())<p class="app-card p-4 text-rose-700" role="alert">{{ $errors->first() }}</p>@endif @include('product.subscription-payment',['subscriptionPayment'=>$screen['subscription_payment_detail']])</div>
@elseif(isset($screen['auth']))
@include('product.identity')
@elseif(isset($screen['chat']))
@include('product.chat')
@elseif(isset($screen['housing']))
@include('product.housing')
@elseif(isset($screen['people']))
@include('product.people')
@elseif(isset($screen['banking']))
@include('product.banking')
@elseif(isset($screen['dashboard']))
@include('product.dashboard')
@else
<div class="space-y-6">
<header class="max-w-3xl">@if(!empty($page['breadcrumbs']))<nav aria-label="Đường dẫn" class="mb-3 flex flex-wrap gap-2 text-sm text-slate-500">@foreach($page['breadcrumbs'] as $crumb)<a class="underline" href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>@unless($loop->last)<span aria-hidden="true">/</span>@endunless @endforeach</nav>@endif<p class="mb-2 text-sm font-medium text-slate-500">{{ $contexts[$context]['label'] }}</p><h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $page['title'] }}</h1></header>
@if(session('status'))<div role="status" class="app-card border-emerald-300 p-4 text-emerald-700 dark:text-emerald-300">{{ session('status') }}</div>@endif
@if($errors->any())<div role="alert" class="app-card border-rose-300 p-4 text-rose-700 dark:text-rose-300"><p class="font-semibold">Vui lòng kiểm tra thông tin:</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@foreach(['invitation_url'=>'Liên kết mời','device_token'=>'Khóa kết nối thiết bị'] as $key=>$label)@if(session($key))<div class="app-card break-all p-4"><label for="{{ $key }}">{{ $label }}</label><input id="{{ $key }}" class="app-input mt-2" readonly value="{{ session($key) }}"></div>@endif @endforeach
@if($screen['notice'])<p class="app-card p-4 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $screen['notice'] }}</p>@endif
@if(!empty($screen['platform_payee']))
<section class="app-card max-w-2xl p-5" aria-label="Tài khoản nhận tiền dịch vụ">
    <h2 class="text-lg font-semibold">Tài khoản nhận tiền dịch vụ {{ config('product.name') }}</h2>
    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Ngân hàng</dt><dd class="mt-1 font-medium">{{ $screen['platform_payee']['bank_name'] }}</dd></div>
        <div><dt class="text-slate-500">Người nhận</dt><dd class="mt-1 font-medium">{{ $screen['platform_payee']['account_holder_name'] }}</dd></div>
        <div><dt class="text-slate-500">Số tài khoản</dt><dd class="mt-1 font-medium">{{ $screen['platform_payee']['masked_number'] }}</dd></div>
    </dl>
</section>
@endif
@if(!empty($screen['activity_filter']))@include('product.activity-filter')@endif
@if($screen['stats'])<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@foreach($screen['stats'] as $label=>$value)<article class="app-card p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-bold">{{ $value }}</p></article>@endforeach</section>@endif
@if(!empty($screen['payment_table']))@include('product.record-table',['table'=>$screen['payment_table']])@endif
@if($screen['links'])<nav class="flex flex-wrap gap-2" aria-label="Công việc liên quan">@foreach($screen['links'] as $link)<a class="app-button-muted" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>@endif
@if(!empty($screen['business']))@include('product.business')@endif
@if(!empty($screen['admin_filter']))@include('product.admin-filter')@endif
@if($screen['marketplace'])<section class="rounded-3xl p-6 text-white sm:p-8" style="background:linear-gradient(120deg,var(--app-primary),var(--app-accent))"><h2 class="text-xl font-bold sm:text-2xl">Tìm một nơi ở phù hợp với bạn</h2><p class="mt-2 text-white/90">Giá thuê, chi phí và điều kiện rõ ràng để bạn dễ dàng lựa chọn.</p></section>@endif
@if(in_array($page['slug'],['marketplace-home','marketplace-search','favorites','compare-listings','properties','workspace-listings','maintenance','roommate-discovery','admin-accounts','moderation']))
<form method="get" class="app-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
@if($context === 'workspace')<input type="hidden" name="workspace_id" value="{{ $currentWorkspace->id }}">@endif
<input type="hidden" name="context" value="{{ $context }}">@foreach(['id','room_id'] as $key)@if(request($key))<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endif @endforeach
<div class="sm:col-span-2"><label for="search-q" class="mb-1 block text-sm">Tìm kiếm</label><input id="search-q" name="q" class="app-input" value="{{ request('q') }}" placeholder="Nhập tên hoặc khu vực…"></div>
@if($screen['marketplace'])
@foreach(['min_price'=>'Giá từ (đồng)','max_price'=>'Giá đến (đồng)','min_area'=>'Diện tích tối thiểu (m²)'] as $key=>$label)<div><label for="{{ $key }}" class="mb-1 block text-sm">{{ $label }}</label><input id="{{ $key }}" type="number" min="0" name="{{ $key }}" class="app-input" value="{{ request($key) }}"></div>@endforeach
<div><label for="sort" class="mb-1 block text-sm">Sắp xếp</label><select id="sort" name="sort" class="app-input"><option value="newest">Mới nhất</option><option value="price_asc" @selected(request('sort')==='price_asc')>Giá tăng dần</option><option value="price_desc" @selected(request('sort')==='price_desc')>Giá giảm dần</option></select></div>
@endif
<div class="flex items-end gap-2"><button class="app-button-primary">Tìm kiếm</button><a class="app-button-muted" href="{{ route('ui.show',['slug'=>$page['slug'],'context'=>$context]+request()->only(['id','room_id','workspace_id'])) }}">Đặt lại</a></div>
</form>
@endif
@if(!empty($screen['tabs']))<nav class="flex flex-wrap gap-2" aria-label="Danh mục">@foreach($screen['tabs'] as $link)<a class="app-button-muted" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>@endif
@if($screen['forms'])<div class="flex flex-wrap gap-3">@foreach($screen['forms'] as $form)@include('product.form-dialog')@endforeach</div>@endif
@if(!empty($screen['request_table']))@include('product.record-table',['table'=>$screen['request_table']])@endif
@if(!empty($screen['request_pagination'])){{ $screen['request_pagination']->links() }}@endif
@if(!empty($screen['deposit_table']))@include('product.record-table',['table'=>$screen['deposit_table']])@endif
@if(!empty($screen['deposit_pagination'])){{ $screen['deposit_pagination']->links() }}@endif
@if(isset($screen['record_table']))
@include('product.record-table')
@elseif($screen['forms'] || $screen['records'])
<section class="independent-cards {{ $screen['marketplace']?'marketplace-cards':'' }}" x-data="independentCards">

@foreach($screen['records'] as $record)
@include('product.record')
@endforeach
</section>
@elseif(!$screen['stats'])<div class="app-card p-10 text-center"><h2 class="font-semibold">Chưa có dữ liệu phù hợp</h2><p class="mt-2 text-sm text-slate-500">Thử điều chỉnh tìm kiếm hoặc quay lại sau khi có thông tin mới.</p></div>@endif
@if($screen['pagination'])<div>{{ $screen['pagination']->links() }}</div>@endif
</div>
@endif
@endsection
