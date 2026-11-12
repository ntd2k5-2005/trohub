<div class="space-y-6">
<header><p class="text-sm text-slate-500">{{ $currentWorkspace->name }}</p><h1 class="mt-2 text-3xl font-bold">Tài khoản nhận tiền</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-slate-500">Chọn tài khoản cho khu quản lý hoặc từng nhà. Thay đổi chỉ áp dụng cho khoản thu phát hành sau đó.</p></header>
<nav class="flex flex-wrap gap-2" aria-label="Cài đặt khu quản lý">@foreach($screen['tabs'] as $link)<a class="app-button-muted" href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>
@if(session('status'))<p role="status" class="app-card p-4 text-emerald-700 dark:text-emerald-300">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="app-card p-4 text-rose-700 dark:text-rose-300"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($screen['bank_catalog']['stale'])<p role="status" class="app-card p-4 text-sm">{{ $screen['bank_catalog']['banks'] ? 'Danh sách ngân hàng đang dùng bản đã lưu. Bạn có thể tải lại để cập nhật.' : 'Chưa tải được danh sách ngân hàng. Vui lòng thử lại; các khoản thu đã phát hành vẫn giữ thông tin chuyển khoản.' }} <a href="{{ request()->fullUrl() }}" class="underline">Tải lại</a></p>@endif
@if($screen['forms'])<div class="flex flex-wrap gap-3">@foreach($screen['forms'] as $form)@include('product.form-dialog')@endforeach</div>@endif
@if(!$screen['records'])<div class="app-card p-8 text-center"><h2 class="text-lg font-bold">Chưa có tài khoản nhận tiền</h2><p class="mt-2 text-sm text-slate-500">Thêm tài khoản, sau đó chọn nơi nhận tiền để phát hành thông tin chuyển khoản cho người thuê.</p></div>@endif
@if(isset($screen['record_table']) && $screen['records'])@include('product.record-table')@endif
@if($screen['pagination']){{ $screen['pagination']->links() }}@endif
</div>
