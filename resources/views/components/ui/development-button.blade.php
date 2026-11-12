@props(['feature'])
@if(in_array($feature, config('availability.developing', []), true))
<button type="button" data-development-feature="{{ $feature }}" {{ $attributes->class(['app-button-muted']) }} @click="$dispatch('feature-unavailable')">{{ $slot }}</button>
@endif
