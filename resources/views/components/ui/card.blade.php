@props(['padding' => true])
<section {{ $attributes->class(['rounded-[var(--app-radius)] border border-slate-200/80 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900', 'p-5' => $padding]) }}>{{ $slot }}</section>
