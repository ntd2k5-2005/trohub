@props(['variant' => 'primary', 'type' => 'button'])
@php
$styles = [
  'primary' => 'bg-indigo-600 text-white hover:bg-indigo-500',
  'secondary' => 'border border-slate-200 bg-white hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800',
  'danger' => 'bg-rose-600 text-white hover:bg-rose-500',
  'ghost' => 'bg-transparent hover:bg-slate-100 dark:hover:bg-slate-800',
];
@endphp
<button type="{{ $type }}" {{ $attributes->class(['inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition', $styles[$variant] ?? $styles['primary']]) }}>{{ $slot }}</button>
