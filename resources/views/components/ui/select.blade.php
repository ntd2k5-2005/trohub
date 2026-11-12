@props(['label' => null, 'hint' => null])
<label class="block">
  @if($label)<span class="mb-1.5 block text-sm font-medium">{{ $label }}</span>@endif
  <select {{ $attributes->class(['w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 outline-none ring-indigo-500/20 focus:ring-4 dark:border-slate-700 dark:bg-slate-900']) }}>{{ $slot }}</select>
  @if($hint)<span class="mt-1.5 block text-xs text-slate-500">{{ $hint }}</span>@endif
</label>
