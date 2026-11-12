<section class="app-card p-5 space-y-5" aria-labelledby="social-accounts-title">
    <div><h2 id="social-accounts-title" class="text-lg font-bold">Tài khoản liên kết</h2><p class="mt-2 text-sm text-slate-500">Quản lý cách đăng nhập bằng Google và Facebook của bạn.</p></div>
    @if(!$screen['has_password'])
        <p class="text-sm">Bạn đang đăng nhập bằng tài khoản liên kết. Hãy thiết lập mật khẩu qua email trước khi thay đổi liên kết.</p>
        <a class="app-button-muted" href="{{ route('ui.show', ['slug' => 'recover-access', 'context' => 'public', 'email' => auth()->user()->email]) }}">Thiết lập mật khẩu qua email</a>
    @endif
    @foreach(config('social_login.providers') as $provider => $definition)
        @php($linked = in_array($provider, $screen['social_accounts'], true))
        <div class="border-t border-slate-200 pt-4 dark:border-slate-700">
            <h3 class="font-semibold">{{ $definition['label'] }}</h3><p class="mt-1 text-sm text-slate-500">{{ $linked ? 'Đã liên kết' : 'Chưa liên kết' }}</p>
            @if($screen['has_password'] && ($linked || app(\App\Modules\Identity\SocialLogin::class)->configured($provider)))
                <form method="post" action="{{ route($linked ? 'social.unlink' : 'social.link', ['provider' => $provider]) }}" class="mt-3 space-y-3" x-data="{ sending: false }" @submit="sending = true">
                    @csrf
                    <label class="block text-sm" for="social-password-{{ $provider }}">Mật khẩu hiện tại</label>
                    <input class="app-input" id="social-password-{{ $provider }}" name="current_password" type="password" required autocomplete="current-password">
                    <button class="app-button-muted" :disabled="sending">{{ $linked ? 'Hủy liên kết '.$definition['label'] : 'Liên kết '.$definition['label'] }}</button>
                </form>
            @elseif(!$linked)
                <p class="mt-2 text-sm text-slate-500">{{ app(\App\Modules\Identity\SocialLogin::class)->configured($provider) ? 'Thiết lập mật khẩu để xác nhận liên kết.' : 'Phương thức này hiện chưa sẵn sàng.' }}</p>
            @endif
        </div>
    @endforeach
</section>
