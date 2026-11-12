@php
$formKey = \App\Support\FormIdentity::key($form);
$stepConfig = config('workflow_ui.steps', [])[$form['action']] ?? [];
$stepNames = array_keys($stepConfig);
$fieldSteps = [];
foreach (array_values($stepConfig) as $stepIndex => $names) foreach ($names as $name) $fieldSteps[$name] = $stepIndex;
$initialStep = 0;
if (old('_form') === $formKey) foreach ($form['fields'] as $f) if ($errors->has($f['name'])) { $initialStep = $fieldSteps[$f['name']] ?? 0; break; }
@endphp
<form method="post" enctype="multipart/form-data" action="{{ $form['action']==='profile'?route('profile.update'):route('action',['action'=>$form['action']]) }}" class="space-y-4" x-data="workflowForm(@js($stepNames), {{ $initialStep }})" novalidate @submit.prevent="submit($event)" @invalid.capture="invalid($event)">
@csrf
<input type="hidden" name="_form" value="{{ $formKey }}">
<input type="hidden" name="context" value="{{ $context }}">
@if($context==='workspace')<input type="hidden" name="workspace_id" value="{{ $currentWorkspace->id }}">@endif
@if($form['id'])<input type="hidden" name="id" value="{{ $form['id'] }}">@endif
@if(count($stepNames)>1)<nav class="workflow-steps" aria-label="Các bước nhập thông tin">@foreach($stepNames as $index=>$name)<button type="button" :aria-current="step === {{ $index }} ? 'step' : null" @click="go({{ $index }})">{{ $index+1 }}. {{ $name }}</button>@endforeach</nav>@endif
@foreach($form['fields'] as $field)
@php
if (old('_form')===$formKey && $field['type']!=='password') $field['value']=old($field['name'], in_array($field['type'], ['permissions','scope']) ? [] : $field['value']);
$inputId=$formKey.'-'.$field['name'];
@endphp
@if($field['type']==='hidden')<input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
@else
<div data-step="{{ $fieldSteps[$field['name']] ?? 0 }}" @if(count($stepNames)>1) x-show="step === {{ $fieldSteps[$field['name']] ?? 0 }}" @endif><label for="{{ $inputId }}" class="mb-1.5 block text-sm font-medium">{{ $field['label'] }}@if($field['required']) *@endif</label>
@if($field['type']==='choices')
<fieldset id="{{ $inputId }}" class="space-y-2"><legend class="sr-only">{{ $field['label'] }}</legend>@foreach($field['options'] as $key=>$label)<label class="flex items-center gap-2"><input type="checkbox" name="{{ $field['name'] }}[]" value="{{ $key }}" @checked(in_array($key,$field['value']??[],true))><span>{{ $label }}</span></label>@endforeach</fieldset>
@elseif($field['type']==='permissions')
@include('product.permissions')
@elseif($field['type']==='scope')
@include('product.scope')
@elseif($field['type']==='permission-template')
<select id="{{ $inputId }}" name="{{ $field['name'] }}" class="app-input" @required($field['required']) @change="$el.form.querySelector('.permission-picker').dispatchEvent(new CustomEvent('permission-template', {detail: JSON.parse($event.target.selectedOptions[0].dataset.permissions || '[]')}))">
<option value="">Chọn nhóm mẫu</option>
@foreach($field['options'] as $key => $template)<option value="{{ $key }}" data-permissions="{{ json_encode($template['permissions']) }}" @selected((string)$field['value']===(string)$key)>{{ $template['name'] }}</option>@endforeach
</select>
@elseif($field['type']==='groups')
@include('product.group-picker')
@elseif($field['type']==='amenities')
@include('product.amenities')
@elseif($field['type']==='housing-type')
<input id="{{ $inputId }}" name="{{ $field['name'] }}" class="app-input" list="{{ $inputId }}-options" value="{{ \App\Modules\Property\HousingTypes::label($field['value']) === 'Chưa cập nhật' ? '' : \App\Modules\Property\HousingTypes::label($field['value']) }}" maxlength="{{ config('housing.room_type_max_length') }}" placeholder="Chọn gợi ý hoặc nhập loại hình khác" @required($field['required'])>
<datalist id="{{ $inputId }}-options">@foreach($field['options'] as $label)<option value="{{ $label }}">@endforeach</datalist>
@elseif($field['type']==='room-select')
@include('product.room-select')
@elseif($field['type']==='search-select')
<div x-data="searchSelect(@js($field['options']), @js($field['value']))"><input class="app-input mb-2" x-model="query" type="search" aria-label="Tìm lựa chọn" placeholder="Nhập để tìm…"><select id="{{ $inputId }}" name="{{ $field['name'] }}" class="app-input" x-model="selected" @required($field['required'])><option value="">Chọn {{ mb_strtolower($field['label']) }}</option><template x-for="item in matches" :key="item[0]"><option :value="item[0]" x-text="item[1]"></option></template></select></div>
@elseif(in_array($field['type'],['select','multiselect']))
<select id="{{ $inputId }}" name="{{ $field['name'] }}{{ $field['type']==='multiselect'?'[]':'' }}" class="app-input" @required($field['required']) @if($field['type']==='multiselect') multiple size="5" @endif>
@if($field['type']==='select')<option value="">Chọn {{ mb_strtolower($field['label']) }}</option>@endif
@foreach($field['options'] as $key=>$label)<option value="{{ $key }}" @selected(is_array($field['value']) ? in_array($key,$field['value']) : (string)$field['value']===(string)$key)>{{ $label }}</option>@endforeach
</select>
@elseif($field['name']==='image_urls')
@include('product.gallery-upload')
<div x-data="{ urls: @js($field['value'] ?? ''), get images() { return this.urls.split(/\r?\n/).map(url => url.trim()).filter(url => url.startsWith('https://')).slice(0, {{ (int) config('housing.max_images') }}); } }">
<textarea id="{{ $inputId }}" name="image_urls" rows="3" class="app-input" x-model.debounce.600ms="urls" placeholder="https://…">{{ $field['value'] }}</textarea>
<p class="mt-2 text-xs leading-5 text-slate-500">Tối đa {{ config('housing.max_images') }} ảnh. Ảnh đầu tiên là ảnh chính; để trống để dùng ảnh có sẵn của nhà hoặc phòng.</p>
<div class="housing-thumbnails" x-show="images.length"><template x-for="(url, index) in images" :key="index"><img :src="url" :alt="'Ảnh ' + (index + 1)" class="h-16 w-20 shrink-0 rounded-lg object-cover" referrerpolicy="no-referrer" x-on:error="$el.style.visibility = 'hidden'" x-on:load="$el.style.visibility = 'visible'"></template></div>
</div>
@elseif($field['type']==='textarea')<textarea id="{{ $inputId }}" name="{{ $field['name'] }}" rows="3" class="app-input" @required($field['required'])>{{ $field['value'] }}</textarea>
@elseif($field['type']==='checkbox')<input id="{{ $inputId }}" type="checkbox" name="{{ $field['name'] }}" value="1" @required($field['required']) class="h-5 w-5">
@elseif($field['type']==='password')
<div class="relative" x-data="{ visible: false }"><input id="{{ $inputId }}" name="{{ $field['name'] }}" :type="visible ? 'text' : 'password'" type="password" class="app-input pr-16" @required($field['required']) autocomplete="{{ $field['name']==='current_password' ? 'current-password' : 'new-password' }}"><button type="button" class="absolute right-3 top-3 text-xs font-semibold" @click="visible = !visible" :aria-pressed="visible" :aria-label="(visible ? 'Ẩn ' : 'Hiện ') + @js(mb_strtolower($field['label']))" x-text="visible ? 'Ẩn' : 'Hiện'">Hiện</button></div>
@else<input id="{{ $inputId }}" name="{{ $field['name'] }}" type="{{ $field['type'] }}" value="{{ $field['type']==='file'?'':$field['value'] }}" class="app-input" @required($field['required']) @if($field['type']==='number') step="any" @endif @if($field['type']==='file') accept="{{ $field['accept'] ?? 'image/jpeg,image/png,image/webp' }}" @endif>
@endif
@if(old('_form')===$formKey)@error($field['name'])<p class="mt-2 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p>@enderror @endif
</div>
@endif
@endforeach
<div class="flex flex-wrap justify-between gap-3">@if(count($stepNames)>1)<button type="button" class="app-button-muted" x-show="step > 0" @click="go(step-1)">Quay lại</button><button type="button" class="app-button-primary" x-show="step < steps.length-1" @click="go(step+1)">Tiếp tục</button>@endif<button class="app-button-primary" @if(count($stepNames)>1) x-show="step === steps.length-1" @endif :disabled="sending"><span x-show="!sending">{{ $form['label'] }}</span><span x-cloak x-show="sending">Đang lưu…</span></button></div>
</form>
