@if($record['links'])<div class="mt-5 flex flex-wrap gap-2">@foreach($record['links'] as $link)<a href="{{ $link['url'] }}" class="app-button-muted">{{ $link['label'] }}</a>@endforeach</div>@endif
@foreach($record['forms'] as $form)
@if(!$form['fields'])<div class="mt-3">@include('product.form')</div>
@else<div class="mt-3">@include('product.form-dialog')</div>@endif
@endforeach
