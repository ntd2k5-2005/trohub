@php($detail = in_array($screen['housing'], ['listing-detail', 'room-detail']))
<div class="housing-page space-y-6 {{ $screen['housing']==='management' ? 'housing-management' : '' }}">
@if(!empty($screen['preview_back']))<div class="app-card flex flex-wrap items-center justify-between gap-3 p-4"><span class="font-semibold">Bản xem trước tin cho thuê</span><a href="{{ $screen['preview_back'] }}" class="app-button-muted">Trở về quản lý tin</a></div>@endif
@if(session('status'))<div role="status" class="app-card p-4 text-emerald-700 dark:text-emerald-300">{{ session('status') }}</div>@endif
@if($errors->any())<div role="alert" class="app-card p-4 text-rose-700 dark:text-rose-300"><p class="font-semibold">Vui lòng kiểm tra thông tin:</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(!$detail)
<header class="housing-heading {{ $screen['marketplace'] ? 'housing-hero' : '' }}">
<div><p class="housing-eyebrow">{{ $screen['marketplace'] ? 'KHÔNG GIAN SỐNG CỦA BẠN' : $currentWorkspace->name }}</p><h1>{{ $screen['marketplace'] && !in_array($page['slug'], ['favorites','compare-listings']) ? 'Tìm một nơi ở bạn muốn trở về' : $page['title'] }}</h1><p class="housing-lead">{{ $screen['marketplace'] ? 'Khám phá phòng phù hợp, xem chi phí và trao đổi trực tiếp với bên cho thuê.' : 'Theo dõi từng nhà, từng phòng và chăm chút thông tin cho khách tìm thuê.' }}</p></div>
@if($screen['marketplace'])<a href="#housing-results" class="app-button-muted">Khám phá các phòng <span aria-hidden="true">↓</span></a>@endif
</header>
@if($screen['stats'])<section class="housing-stats">@foreach($screen['stats'] as $label=>$value)<div class="app-card p-4"><span class="text-sm text-slate-500">{{ $label }}</span><strong class="mt-2 block text-2xl">{{ $value }}</strong></div>@endforeach</section>@endif
@if(!empty($screen['tabs']) || $screen['links'])<nav class="flex flex-wrap gap-2" aria-label="Danh mục nhà trọ">@foreach(array_merge($screen['tabs'] ?? [], $screen['links']) as $link)<a class="app-button-muted" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>@endif
@include('product.housing-filters')
@if(!empty($screen['active_filters']))<nav class="flex flex-wrap gap-2" aria-label="Bộ lọc đang dùng">@foreach($screen['active_filters'] as $filter)<a class="housing-chip" href="{{ $filter['url'] }}" aria-label="Bỏ bộ lọc {{ $filter['label'] }}">{{ $filter['label'] }} <span aria-hidden="true">×</span></a>@endforeach</nav>@endif
@if(!empty($screen['landing']))<div class="grid gap-4 sm:grid-cols-3"><section class="app-card p-5"><h2 class="font-bold">Chọn phòng phù hợp</h2><p class="mt-2 text-sm leading-6 text-slate-500">Lọc theo ngân sách, khu vực và ngày bạn muốn chuyển đến.</p></section><section class="app-card p-5"><h2 class="font-bold">Trao đổi và hẹn xem</h2><p class="mt-2 text-sm leading-6 text-slate-500">Liên hệ bên cho thuê, kiểm tra không gian và chi phí trước khi quyết định.</p></section><section class="app-card p-5"><h2 class="font-bold">Theo dõi việc thuê</h2><p class="mt-2 text-sm leading-6 text-slate-500">Xem thời hạn giữ phòng, khoản cọc và hợp đồng trong tài khoản của bạn.</p></section></div>@endif
@if($screen['forms'])<div class="housing-create">@foreach($screen['forms'] as $form)@include('product.form-dialog')@endforeach</div>@endif
@if(!empty($screen['property_moves']))<section class="space-y-4"><h2 class="text-lg font-bold">Đề nghị chuyển khu quản lý</h2>@foreach($screen['property_moves'] as $record)@include('product.record')@endforeach</section>@endif
<div id="housing-results" class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold">{{ $screen['marketplace'] ? 'Phòng dành cho bạn' : (request('tab')==='houses' ? 'Các nhà trọ' : 'Danh sách phòng & tin') }}</h2><span class="text-sm text-slate-500">{{ $screen['pagination']?->total() ?? count($screen['records']) }} kết quả</span></div>
@endif
@if($detail && $screen['records'])
@php($record = $screen['records'][0])
<nav class="text-sm text-slate-500"><a class="hover:underline" href="{{ route('ui.show', ['slug' => $screen['marketplace'] ? 'marketplace-search' : 'properties', 'context' => $context] + ($context==='workspace' ? ['workspace_id'=>$currentWorkspace->id] : [])) }}">← {{ $screen['marketplace'] ? 'Tiếp tục tìm phòng' : 'Nhà & phòng' }}</a></nav>
<div class="housing-detail">
@include('product.housing-gallery', ['images' => $record['images'], 'title' => $record['title']])
<section class="app-card p-5 sm:p-7"><h2 class="text-lg font-bold">Thông tin phòng & chi phí</h2><dl class="housing-facts mt-5">@foreach($record['facts'] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl></section>
@if(!empty($record['description']))<section class="app-card p-5 sm:p-7"><h2 class="text-lg font-bold">Giới thiệu không gian</h2><p class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-600 dark:text-slate-300">{{ $record['description'] }}</p></section>@endif
<aside class="min-w-0 space-y-5"><section class="app-card p-5 sm:p-7"><span class="housing-chip">{{ $record['badge'] }}</span><h1 class="mt-4 text-2xl font-bold leading-snug sm:text-3xl">{{ $record['title'] }}</h1><p class="mt-3 text-sm leading-6 text-slate-500">{{ $record['subtitle'] }}</p><div class="housing-price mt-5">{{ $record['price'] }} <span>/ tháng</span></div><div class="mt-4 flex flex-wrap gap-3"><span class="housing-chip">{{ $record['area'] }} m²</span><span class="housing-chip">Tối đa {{ $record['capacity'] }} người</span>@if(!empty($record['published_date']))<span class="text-sm text-slate-500">Đăng ngày {{ $record['published_date'] }}</span>@endif</div></section>
<div class="housing-contact app-card p-5 sm:p-6">
@if($screen['marketplace'])<div class="flex items-center gap-3"><div class="housing-avatar">{{ mb_substr($record['publisher'],0,1) }}</div><div class="min-w-0"><p class="text-xs text-slate-500">Bên cho thuê</p><h2 class="break-words font-bold">{{ $record['publisher'] }}</h2><p class="mt-1 break-words text-sm text-slate-500">{{ $record['property_name'] }}</p></div></div><div class="mt-6 border-t border-slate-100 pt-5 dark:border-slate-800">@include('product.housing-contact')</div>
@else<h2 class="text-lg font-bold">Quản lý phòng</h2>@endif
@include('product.housing-actions')
</div></aside>
</div>
@elseif($page['slug']==='compare-listings' && $screen['records'])
@include('product.compare-table')
@elseif(isset($screen['record_table']))
@include('product.record-table')
@elseif($screen['records'])
<section class="independent-cards housing-cards {{ $screen['marketplace'] ? 'housing-public-cards' : '' }}" x-data="independentCards">
@foreach($screen['records'] as $record)
<article class="app-card housing-card overflow-hidden">
<div class="housing-cover">@if(!empty($record['url']))<a href="{{ $record['url'] }}" aria-label="Xem {{ $record['title'] }}">@endif<img src="{{ $record['images'][0] }}" alt="{{ $record['title'] }}" loading="lazy" referrerpolicy="no-referrer" x-data x-on:error.once="$el.onerror = null; $el.src = @js(config('housing.fallback_image'))">@if(!empty($record['url']))</a>@endif<span class="housing-cover-badge">{{ $record['badge'] }}</span><span class="housing-photo-count">{{ count($record['images']) }} ảnh</span></div>
<div class="p-5"><h3 class="housing-card-title">@if(!empty($record['url']))<a href="{{ $record['url'] }}">{{ $record['title'] }}</a>@else{{ $record['title'] }}@endif</h3><p class="mt-2 text-sm leading-6 text-slate-500">{{ $record['subtitle'] }}</p>
@if(!empty($record['price']))<div class="housing-price mt-4">{{ $record['price'] }} <span>/ tháng</span></div>@endif
@if(isset($record['area']))<div class="mt-3 flex flex-wrap gap-3 text-sm text-slate-500"><span>{{ $record['area'] }} m²</span><span>·</span><span>{{ $record['capacity'] }} người</span></div>@endif
@if(!$screen['marketplace'] || $page['slug']==='compare-listings')<dl class="housing-facts mt-5">@foreach($record['facts'] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>@endif
@if(!empty($record['description']) && !$screen['marketplace'])<p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-500">{{ $record['description'] }}</p>@endif
@include('product.housing-actions')
</div></article>
@endforeach
</section>
@else<div class="app-card p-10 text-center"><h2 class="text-lg font-bold">Chưa có dữ liệu phù hợp</h2><p class="mt-2 text-sm text-slate-500">Thử thay đổi khu vực, mức giá hoặc bỏ bớt bộ lọc.</p></div>@endif
@if($screen['pagination'] && !$detail)<div>{{ $screen['pagination']->links() }}</div>@endif
</div>
