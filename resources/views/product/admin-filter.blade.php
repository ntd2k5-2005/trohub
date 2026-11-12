<form method="get" class="app-card flex flex-wrap items-end gap-3 p-4" aria-label="Lọc hồ sơ quản trị">
    @if(!empty($screen['admin_filter']['tab']))<input type="hidden" name="tab" value="{{ $screen['admin_filter']['tab'] }}">@endif
    <input type="hidden" name="context" value="{{ $context }}"><input type="hidden" name="section" value="{{ $screen['admin_filter']['section'] }}">
    <div class="min-w-0 flex-1"><label for="admin-search" class="mb-1 block text-sm">Tìm kiếm</label><input id="admin-search" type="search" name="q" class="app-input" value="{{ request('q') }}" placeholder="Tên, email hoặc mã yêu cầu…" maxlength="150"></div>
    @if($screen['admin_filter']['states'])<div><label for="admin-status" class="mb-1 block text-sm">Trạng thái hồ sơ</label><select id="admin-status" name="status" class="app-input"><option value="">Tất cả</option>@foreach($screen['admin_filter']['states'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></div>@endif
    <button class="app-button-primary">Tìm kiếm</button><a class="app-button-muted" href="{{ route('ui.show', ['slug'=>'admin-dashboard','context'=>$context,'section'=>$screen['admin_filter']['section'],'tab'=>$screen['admin_filter']['tab']??null]) }}">Đặt lại</a>
</form>
