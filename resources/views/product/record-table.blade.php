@php($table=$table??$screen['record_table'])
<section class="record-list app-card" aria-label="{{ $table['title'] }}" data-record-list>
    <header class="record-list-heading"><div><h2>{{ $table['title'] }}</h2><p>{{ number_format($table['total'],0,',','.') }} kết quả</p></div>
    @if($table['sortable'])<form method="get" class="record-order">@foreach(request()->except(['order','page','item']) as $name=>$value)@if(is_array($value))@foreach($value as $part)<input type="hidden" name="{{ $name }}[]" value="{{ $part }}">@endforeach @else<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif @endforeach<label for="record-order">Sắp xếp</label><select id="record-order" name="order" class="app-input" onchange="this.form.submit()"><option value="">Mặc định</option><option value="newest" @selected(request('order')==='newest')>Mới nhất</option><option value="oldest" @selected(request('order')==='oldest')>Cũ nhất</option></select><noscript><button class="app-button-muted">Áp dụng</button></noscript></form>@endif
    </header>
    @if($table['records'])
    <div class="record-table-scroll" tabindex="0" aria-label="{{ $table['title'] }} — cuộn ngang để xem thêm">
    <table class="record-table"><thead><tr><th scope="col">{{ $table['title'] }}</th>@foreach($table['columns'] as $column)<th scope="col" @class(['is-secondary'=>$loop->index>=2])>{{ $column }}</th>@endforeach<th scope="col" class="record-actions-heading" aria-label="Thao tác"></th></tr></thead><tbody>
    @foreach($table['records'] as $record)
    <tr x-data @click="if (!$event.target.closest('a,button,summary,dialog,input,select,textarea')) window.location.assign(@js($record['_detail_url']))" data-record-row>
        <th scope="row"><a href="{{ $record['_detail_url'] }}" class="record-identity">@if(!empty($record['images']))<img src="{{ $record['images'][0] }}" alt="" loading="lazy" referrerpolicy="no-referrer" x-on:error.once="$el.src=@js(config('housing.fallback_image'))">@else<span class="record-avatar" aria-hidden="true">{{ mb_substr($record['_primary'],0,1) }}</span>@endif<span class="min-w-0"><strong>{{ $record['_primary'] }}</strong><span class="record-subtitle">{{ $record['subtitle'] }}</span></span></a></th>
        @foreach($table['columns'] as $column)@php($value=$record['facts'][$column]??($column==='Trạng thái'?($record['state_label']??$record['badge']??'—'):'—'))<td @class(['is-secondary'=>$loop->index>=2,'is-money'=>str_contains((string)$value,'₫')])>@if(str_contains(mb_strtolower($column),'trạng thái')||$column==='Thanh toán')<span class="record-state">{{ $value }}</span>@else<span class="record-cell-value">{{ $value }}</span>@endif</td>@endforeach
        <td class="record-row-actions"><div class="flex items-center justify-end gap-1">
            <a href="{{ $record['_table_action']['url'] }}" class="record-detail-link">{{ $record['_table_action']['label'] }} <span aria-hidden="true">↗</span></a>
            <div x-data="formDialog(false)"><button type="button" class="record-icon-button" @click="open()" aria-label="Xem nhanh {{ $record['_primary'] }}" title="Xem nhanh"><x-ui.icon name="eye"/></button><dialog x-ref="dialog" class="workflow-dialog record-preview" aria-label="Xem nhanh {{ $record['_primary'] }}" @cancel.prevent="close()" @click="if($event.target===$el)close()"><div class="workflow-dialog-inner"><header class="workflow-dialog-header"><h2 class="text-lg font-bold">{{ $record['_primary'] }}</h2><button type="button" class="app-button-muted" @click="close()" aria-label="Đóng xem nhanh">✕</button></header><div class="workflow-dialog-body"><p class="mb-4 break-words text-sm text-slate-500">{{ $record['subtitle'] }}</p><dl class="record-preview-facts">@foreach(array_slice($record['facts'],0,config('presentation.preview_fact_limit'),true) as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl><a href="{{ $record['_detail_url'] }}" class="app-button-primary mt-5">Xem đầy đủ thông tin</a></div></div></dialog></div>
            @php($quickForm=collect($record['forms'])->first(fn($f)=>preg_match('/^(Sửa|Chỉnh sửa|Cập nhật|Điều chỉnh)/u',$f['label'])))
            @if($quickForm)@include('product.form-dialog',['form'=>$quickForm,'compactTrigger'=>true])@endif
            @if(!$quickForm)@php($quickAction=collect($record['forms'])->first(fn($f)=>in_array($f['action'],config('presentation.quick_actions'),true)))@if($quickAction)@include('product.form-dialog',['form'=>$quickAction,'compactTrigger'=>true,'compactIcon'=>'check'])@endif @endif
        </div></td>
    </tr>
    @endforeach
    </tbody></table></div>
    @else<div class="record-list-empty"><x-ui.icon name="search"/><h3>Chưa có kết quả phù hợp</h3><p>Thử thay đổi từ khóa hoặc bộ lọc.</p></div>@endif
</section>
