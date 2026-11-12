<form method="get" class="app-card space-y-4 p-4" aria-label="Bộ lọc báo cáo" x-data="{period:@js($period['period']),scope:@js($scope['mode']??'current')}">
    <input type="hidden" name="context" value="{{ $context }}">
    @if($context==='workspace')<input type="hidden" name="workspace_id" value="{{ $currentWorkspace->id }}"><input type="hidden" name="tab" value="business">@else<input type="hidden" name="section" value="{{ request('section') }}">@endif
    <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-sm">Khoảng thời gian<select name="period" class="app-input mt-1" x-model="period">@foreach(['today'=>'Hôm nay','week'=>'7 ngày gần nhất','month'=>'Tháng này','year'=>'Năm này','custom'=>'Khoảng ngày tùy chọn'] as $key=>$label)<option value="{{ $key }}" @selected($period['period']===$key)>{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm" x-show="period==='custom'">Từ ngày<input name="from" type="date" class="app-input mt-1" value="{{ $period['from'] }}" :disabled="period!=='custom'" :required="period==='custom'"></label>
        <label class="text-sm" x-show="period==='custom'">Đến ngày<input name="until" type="date" class="app-input mt-1" value="{{ $period['until'] }}" :disabled="period!=='custom'" :required="period==='custom'"></label>
        @if($scope)
        <label class="text-sm">Khu quản lý<select name="workspace_scope" class="app-input mt-1" x-model="scope">@foreach(['current'=>'Khu đang mở','all'=>'Tất cả khu có quyền','selected'=>'Chọn khu quản lý'] as $key=>$label)<option value="{{ $key }}" @selected($scope['mode']===$key)>{{ $label }}</option>@endforeach</select></label>
        @endif
        <div><button class="app-button-primary">Áp dụng</button></div>
    </div>
    @unless($scope)<div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Loại gói<select name="plan" class="app-input mt-1"><option value="">Tất cả</option>@foreach(['monthly'=>'Theo tháng','yearly'=>'Theo năm'] as $key=>$label)<option value="{{ $key }}" @selected(($period['plan']??null)===$key)>{{ $label }}</option>@endforeach</select></label><label class="text-sm">Tài khoản hoặc mã giao dịch<input class="app-input mt-1" name="q" value="{{ $period['q']??'' }}" maxlength="150"></label></div>@endunless
    @if($scope)<fieldset x-show="scope==='selected'" x-cloak class="rounded-xl border border-slate-200 p-3 dark:border-slate-700"><legend class="px-1 text-sm font-medium">Chọn một hoặc nhiều khu</legend><div class="grid max-h-48 gap-3 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">@foreach($scope['options'] as $id=>$label)<label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-1" name="workspace_ids[]" value="{{ $id }}" @checked(in_array($id,$scope['ids'])) :disabled="scope!=='selected'"><span class="break-words">{{ $label }}</span></label>@endforeach</div></fieldset>@endif
</form>
