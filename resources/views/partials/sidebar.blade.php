<div x-cloak x-show="sidebarOpen" x-transition.opacity class="shell-backdrop" @click="closeSidebar()" aria-hidden="true"></div>
<aside id="app-sidebar" class="shell-sidebar" :class="{'is-open': sidebarOpen}" :inert="mobileViewport && !sidebarOpen" @keydown.tab="trapFocus($event, $el, mobileViewport && sidebarOpen)" aria-label="Điều hướng chính">
    <div class="shell-sidebar-inner">
        <div class="shell-brand-row">
            <a href="{{ $shell['home_url'] }}" class="shell-brand"><img src="{{ config('product.logos.light') }}" class="dark:hidden" width="36" height="36" alt=""><img src="{{ config('product.logos.dark') }}" class="hidden dark:block" width="36" height="36" alt=""><span>{{ config('product.name') }}</span></a>
            <button type="button" class="shell-icon-button lg:hidden" @click="closeSidebar()" aria-label="Đóng menu"><x-ui.icon name="close"/></button>
        </div>
        <div class="shell-context">
            <label for="ui-context">Không gian làm việc</label>
            <form method="get" action="{{ route('ui.enter') }}">
                <select id="ui-context" name="context" class="app-input" onchange="this.form.submit()">
                    @foreach($contexts as $ctx => $meta)<option value="{{ $ctx }}" @selected($ctx === $context)>{{ $meta['label'] }}</option>@endforeach
                </select>
                <noscript><button class="app-button-muted mt-2">Chuyển không gian</button></noscript>
            </form>
        </div>
        @if($context === 'workspace')
        <div class="shell-workspace">
            <form method="get" action="{{ route('ui.enter') }}">
                <input type="hidden" name="context" value="workspace">
                <label for="workspace-switch">Khu đang quản lý @if($availableWorkspaces->count() > 1)<span class="ml-1">({{ $availableWorkspaces->count() }})</span>@endif</label>
                @if($availableWorkspaces->count() > 1)
                    <select id="workspace-switch" name="workspace_id" class="app-input" onchange="this.form.submit()">@foreach($availableWorkspaces as $workspace)<option value="{{ $workspace->id }}" @selected($currentWorkspace->id === $workspace->id)>{{ $workspace->name }}</option>@endforeach</select>
                    <noscript><button class="app-button-muted mt-2">Chuyển khu quản lý</button></noscript>
                @else <p class="font-semibold">{{ $currentWorkspace->name }}</p> @endif
            </form>
            <p class="shell-scope">{{ $shell['scope'] }}</p>
            @if($currentWorkspace->owner_id === auth()->id())
            <a class="mt-3 inline-flex text-xs font-medium text-indigo-600 dark:text-indigo-300" href="{{ route('ui.show', ['slug' => 'landlord-upgrade', 'context' => 'personal']) }}">+ Thêm khu quản lý</a>
            @endif
        </div>
        @endif
        <nav class="shell-nav" aria-label="Chức năng được phép sử dụng">
            @foreach($shell['items']->groupBy('group') as $order => $items)
            <details class="shell-nav-group" open>
                <summary class="shell-nav-heading"><span>{{ config("navigation.groups.$context.$order", 'Chức năng') }}</span><x-ui.icon name="chevron"/></summary>
                <div class="shell-nav-children">
                @foreach($items as $item)
                    <a href="{{ $item['url'] }}" class="shell-nav-link {{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif>
                        <x-ui.icon :name="$item['icon']"/><span>{{ $item['label'] }}</span>@if($item['badge'])<small>{{ min($item['badge'], 99) }}{{ $item['badge'] > 99 ? '+' : '' }}</small>@endif
                    </a>
                @endforeach
                </div>
            </details>
            @endforeach
        </nav>
    </div>
</aside>
