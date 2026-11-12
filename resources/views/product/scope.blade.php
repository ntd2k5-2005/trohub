@php
$allAllowed = $field['all_allowed'] ?? true;
$mode = old('_form') === $formKey ? old('scope_mode', $field['value'] === null && $allAllowed ? 'all' : 'selected') : ($field['value'] === null && $allAllowed ? 'all' : 'selected');
$ids = (array) $field['value'];
@endphp
<fieldset id="{{ $inputId }}" class="scope-picker" x-data="{ mode: @js($mode) }"><legend class="sr-only">{{ $field['label'] }}</legend>
    @if($allAllowed)<label><input type="radio" name="scope_mode" value="all" x-model="mode" @checked($mode==='all')><span>Toàn bộ nhà trong khu quản lý</span></label>@endif
    <label><input type="radio" name="scope_mode" value="selected" x-model="mode" @checked($mode==='selected')><span>Chọn từng nhà được giao</span></label>
    <div x-show="mode === 'selected'" class="scope-picker pl-4">@foreach($field['options'] as $id=>$label)<label><input type="checkbox" name="{{ $field['name'] }}[]" value="{{ $id }}" @checked(in_array($id,$ids)) :disabled="mode !== 'selected'"><span>{{ $label }}</span></label>@endforeach</div>
</fieldset>
