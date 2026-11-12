@php $selectedGroups = array_map('intval', (array) ($field['value'] ?? [])); @endphp
<fieldset class="group-picker space-y-3">
    <legend class="sr-only">{{ $field['label'] }}</legend>
    <input type="hidden" name="_groups_present" value="1">
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach($field['options'] as $key => $option)
        @php $groupName = is_array($option) ? $option['name'] : $option; $count = is_array($option) ? count($option['permissions'] ?? []) : null; @endphp
        <label class="flex min-w-0 cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 hover:border-indigo-400 dark:border-slate-700">
            <input type="checkbox" class="mt-1 h-4 w-4 shrink-0" name="{{ $field['name'] }}[]" value="{{ $key }}" @checked(in_array((int)$key, $selectedGroups, true))>
            <span class="min-w-0"><span class="block break-words font-medium">{{ $groupName }}</span>@if($count !== null)<span class="mt-1 block text-xs text-slate-500">{{ $count }} quyền</span>@endif</span>
        </label>
        @endforeach
    </div>
    @if(!$field['options'])<p class="text-sm text-slate-500">Chưa có nhóm phù hợp để chọn.</p>@endif
</fieldset>
