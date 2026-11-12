@props(['headers' => []])
<div class="overflow-hidden rounded-[var(--app-radius)] border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
  <div class="overflow-x-auto">
    <table class="min-w-full text-left text-sm">
      <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950/40"><tr>@foreach($headers as $header)<th class="whitespace-nowrap px-5 py-3 font-semibold">{{ $header }}</th>@endforeach</tr></thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-800">{{ $slot }}</tbody>
    </table>
  </div>
</div>
