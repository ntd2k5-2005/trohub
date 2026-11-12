<article class="app-card min-w-0 overflow-hidden p-5 sm:p-6">
<h2 class="break-words text-lg font-bold">{{ $record['title'] }}</h2><p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-500">{{ $record['subtitle'] }}</p>
<dl class="mt-4 space-y-3">@foreach($record['facts'] as $label=>$value)@php($longValue = mb_strlen((string)$value)>100 || str_contains((string)$value, "\n"))<div class="grid {{ $longValue ? 'grid-cols-1' : 'grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]' }} gap-3 border-b border-slate-100 pb-2 text-sm last:border-0 dark:border-slate-800"><dt class="break-words text-slate-500">{{ $label }}</dt><dd class="whitespace-pre-line break-words {{ $longValue ? 'text-left leading-7' : 'text-right' }} font-medium">{{ $value }}</dd></div>@endforeach</dl>
@if($record['links'])<div class="mt-5 flex flex-wrap gap-2">@foreach($record['links'] as $link)<a href="{{ $link['url'] }}" class="app-button-muted">{{ $link['label'] }}</a>@endforeach</div>@endif
@foreach($record['payment_requests'] ?? [] as $paymentRequest)@include('product.payment-request')@endforeach
<div class="mt-4 flex flex-wrap gap-2">@foreach($record['forms'] as $form)@include('product.form-dialog')@endforeach</div>
</article>
