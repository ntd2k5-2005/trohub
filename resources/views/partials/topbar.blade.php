<header class="shell-topbar">
    <div class="shell-topbar-inner">
        <button type="button" x-ref="menuTrigger" class="shell-icon-button lg:hidden" @click="openSidebar()" aria-label="Mở menu" aria-controls="app-sidebar" :aria-expanded="sidebarOpen"><x-ui.icon name="menu"/></button>
        <div class="shell-location"><span>{{ $contexts[$context]['label'] }}</span><strong>{{ $context === 'workspace' ? $currentWorkspace->name : config('product.name') }}</strong></div>
        <div class="shell-topbar-actions">
            <button type="button" class="shell-search-button" @click="openCommands()" aria-label="Tìm chức năng"><x-ui.icon name="search"/><span>Tìm chức năng</span><kbd>Ctrl K</kbd></button>
            <label class="shell-theme"><span class="sr-only">Giao diện</span><select aria-label="Giao diện" x-model="theme" @change="applyTheme()"><option value="system">Theo hệ thống</option><option value="light">Sáng</option><option value="dark">Tối</option></select></label>
            @if($shell['notification_url'])<a href="{{ $shell['notification_url'] }}" class="shell-icon-button shell-notification" aria-label="Thông báo{{ $shell['unread'] ? ', '.$shell['unread'].' chưa đọc' : '' }}"><x-ui.icon name="bell"/>@if($shell['unread'])<span>{{ $shell['unread'] > 99 ? '99+' : $shell['unread'] }}</span>@endif</a>@endif
            <div class="shell-account" @click.outside="accountOpen=false" @keydown.escape.stop="accountOpen=false; $refs.accountTrigger.focus()">
                <button type="button" class="shell-account-trigger" x-ref="accountTrigger" @click="accountOpen = !accountOpen" :aria-expanded="accountOpen" aria-controls="account-menu" aria-label="Menu tài khoản"><span class="shell-avatar">{{ mb_strtoupper(mb_substr($uiAccess->displayName,0,1)) }}</span><span class="shell-account-name">{{ $uiAccess->displayName }}</span><x-ui.icon name="chevron"/></button>
                <div id="account-menu" x-cloak x-show="accountOpen" x-transition.opacity class="shell-account-menu">
                    <div class="shell-account-info"><strong>{{ $uiAccess->displayName }}</strong><span>{{ auth()->user()->email }}</span></div>
                    <label class="shell-account-theme">Giao diện<select aria-label="Giao diện tài khoản" x-model="theme" @change="applyTheme()"><option value="system">Theo hệ thống</option><option value="light">Sáng</option><option value="dark">Tối</option></select></label>
                    @if($shell['profile_url'])<a href="{{ $shell['profile_url'] }}"><x-ui.icon name="user"/>Hồ sơ và bảo mật</a>@endif
                    @if($uiAccess->canUseContext('public'))<a href="{{ route('ui.enter', ['context'=>'public']) }}"><x-ui.icon name="search"/>Khám phá phòng trọ</a>@endif
                    <form method="post" action="{{ route('logout') }}">@csrf<button type="submit"><x-ui.icon name="logout"/>Đăng xuất</button></form>
                </div>
            </div>
        </div>
    </div>
</header>
