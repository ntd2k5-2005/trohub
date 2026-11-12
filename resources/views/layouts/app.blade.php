<!doctype html>
<html lang="vi" class="h-full" data-housing-fallback="{{ config('housing.fallback_image') }}" data-ui-module="{{ $page['_asset_key'] ?? '' }}" data-default-theme="{{ config('ui.default_theme','system') }}">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="{{ config('ui.theme.primary') }}">
    <meta name="description" content="{{ $page['description'] ?? config('product.tagline') }}">
    <title>{{ $page['title'] ?? config('product.name') }} · {{ config('product.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset(ltrim(config('product.favicon'),'/')) }}">
    <script>
      (() => {
        const saved = localStorage.getItem('theme') || @json(config('ui.default_theme', 'system'));
        const dark = saved === 'dark' || (saved === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
      })();
    </script>
    <style>
      :root{
        --app-primary:{{ config('ui.theme.primary') }};
        --app-accent:{{ config('ui.theme.accent') }};
        --app-radius:{{ config('ui.theme.radius') }};
        --app-sidebar:{{ config('ui.theme.sidebar_width') }};
        --app-max:{{ config('ui.theme.content_max_width') }};
      }
    </style>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="min-h-full" x-data="appShell" @keydown.window.ctrl.k.prevent="openCommands()" @keydown.window.meta.k.prevent="openCommands()" @keydown.window.escape="escapeShell()">
  <a href="#main-content" class="shell-skip">Đến nội dung chính</a>
  @if(isset($screen['auth']))
    <main id="main-content" class="auth-page">@yield('content')</main>
  @elseif($context === 'public')
    @include('partials.public-header')
    <main id="main-content" tabindex="-1" class="mx-auto w-full max-w-[var(--app-max)] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
      @yield('content')
    </main>
  @else
    <div class="shell-frame">
      @include('partials.sidebar')
      <div class="min-w-0" :inert="mobileViewport && sidebarOpen">
        @include('partials.topbar')
        <main id="main-content" tabindex="-1" class="shell-content {{ isset($screen['chat']) ? 'shell-chat-content' : '' }}">
          @yield('content')
        </main>
      </div>
    </div>
  @endif

  @include('partials.command-palette')
  @include('partials.feature-toast')

</body>
</html>
