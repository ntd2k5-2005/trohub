@props(['tone'=>'neutral'])
@php($map=['success'=>'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300','warning'=>'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300','danger'=>'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300','info'=>'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300','neutral'=>'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'])
<span {{ $attributes->class(['inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',$map[$tone]??$map['neutral']]) }}>{{ $slot }}</span>
