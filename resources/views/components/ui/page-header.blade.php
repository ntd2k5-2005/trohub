@props(['title', 'description' => null])
<header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div class="min-w-0"><h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h1>@if($description)<p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500 sm:text-base">{{ $description }}</p>@endif</div>
  @isset($actions)<div class="flex shrink-0 flex-wrap gap-2">{{ $actions }}</div>@endisset
</header>
