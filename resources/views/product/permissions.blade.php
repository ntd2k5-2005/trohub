@php
$categories = app(\App\Modules\AccessControl\PermissionCatalog::class)->grouped($field['options']);
$selected = array_values(array_intersect((array) $field['value'], array_keys($field['options'])));
@endphp
<fieldset id="{{ $inputId }}" class="permission-picker" x-data="permissionPicker(@js($categories), @js($selected))" @permission-template.stop="selected = $event.detail.filter(code => categories.some(group => group.permissions.some(p => p.code === code)))">
    <legend class="sr-only">{{ $field['label'] }}</legend>
    <input type="hidden" name="_permissions_present" value="1">
    <div class="permission-toolbar">
        <label class="permission-search"><span class="sr-only">Tìm quyền</span><input type="search" class="app-input" placeholder="Tìm quyền…" x-model="query" @keydown.enter.prevent></label>
        <span class="permission-count" aria-live="polite"><span x-text="selected.length">{{ count($selected) }}</span> quyền đã chọn</span>
    </div>
    <div class="permission-groups">
    @foreach($categories as $index => $category)
        <section class="permission-group" x-show="visibleGroup({{ $index }})">
            <label class="permission-heading">
                <input type="checkbox" data-permission-group="{{ $index }}" :checked="allSelected({{ $index }})" x-effect="$el.indeterminate = partiallySelected({{ $index }})" @change="toggleGroup({{ $index }}, $event.target.checked)">
                <span>{{ $category['name'] }}</span><small x-text="groupCount({{ $index }}) + '/{{ count($category['permissions']) }}'"></small>
            </label>
            <div class="permission-options">
            @foreach($category['permissions'] as $permission)
                <label class="permission-option" x-show="matches(@js($permission['name']))" :class="{'is-selected': selected.includes(@js($permission['code']))}">
                    <input type="checkbox" name="{{ $field['name'] }}[]" value="{{ $permission['code'] }}" x-model="selected" @checked(in_array($permission['code'], $selected, true))>
                    <span>{{ $permission['name'] }}</span>
                </label>
            @endforeach
            </div>
        </section>
    @endforeach
    <p class="permission-empty" x-cloak x-show="!categories.some((_, index) => visibleGroup(index))">Không tìm thấy quyền phù hợp.</p>
    </div>
</fieldset>
