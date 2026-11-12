<header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-950/85">
  <div class="mx-auto flex h-16 max-w-[var(--app-max)] items-center gap-2 px-4 sm:gap-4 sm:px-6 lg:px-8">
    <a href="{{ route('ui.show', ['slug' => 'marketplace-home', 'context' => 'public']) }}" class="flex shrink-0 items-center gap-2.5">
      <img src="{{ config('product.logos.light') }}" alt="" class="h-9 w-9 rounded-xl">
      <span class="hidden font-bold tracking-tight sm:inline">{{ config('product.name') }}</span>
    </a>

    <nav class="flex items-center gap-1 whitespace-nowrap">
      <a class="app-button-ghost px-3 sm:px-4" href="{{ route('ui.show', ['slug' => 'marketplace-search', 'context' => 'public']) }}">Tìm phòng</a>
      @if($uiAccess->canUseContext('personal'))
        <a class="app-button-ghost hidden sm:inline-flex" href="{{ route('ui.show', ['slug' => 'favorites', 'context' => 'personal']) }}">Đã lưu</a>
      @endif
    </nav>

    <div class="ml-auto flex shrink-0 items-center gap-2 whitespace-nowrap">
      <button class="app-button-muted hidden px-3 sm:inline-flex" @click="commandOpen=true" title="Tìm nhanh" aria-label="Tìm nhanh">⌕</button>
      <button class="app-button-muted px-3" @click="cycleTheme()" :title="'Giao diện: ' + themeLabel()" aria-label="Đổi giao diện sáng tối">◐</button>

      @if($uiAccess->isGuest())
        <a href="{{ route('ui.show', ['slug' => 'login', 'context' => 'public']) }}" class="app-button-muted hidden sm:inline-flex">Đăng nhập</a>
        <a href="{{ route('ui.show', ['slug' => 'register', 'context' => 'public']) }}" class="app-button-primary">Đăng ký</a>
      @else
        @php($accountContext = $uiAccess->canUseContext('personal') ? 'personal' : ($uiAccess->allowedContexts[0] ?? 'public'))
        @php($accountHome = config("navigation.contexts.$accountContext.home", 'marketplace-search'))
        <a href="{{ route('ui.show', ['slug' => $accountHome, 'context' => $accountContext]) }}" class="app-button-primary">Tài khoản</a>
      @endif
    </div>
  </div>
</header>
