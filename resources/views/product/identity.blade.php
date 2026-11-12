@php
    $mode = $screen['auth'];
    $reset = $mode === 'recover-access' && request('token');
    $copy = config('identity_ui.pages.'.($reset ? 'reset' : $mode));
    $register = $mode === 'register';
    $login = $mode === 'login';
    $action = $register ? route('register.store') : ($login ? route('login.store') : ($reset ? route('password.update') : route('password.email')));
@endphp
<div class="auth-shell">
    <header class="auth-header">
        <a href="{{ route('home') }}" class="auth-brand" aria-label="{{ config('product.name') }} — Trang chủ">
            <img src="{{ config('product.logos.light') }}" alt="" width="38" height="38" class="dark:hidden">
            <img src="{{ config('product.logos.dark') }}" alt="" width="38" height="38" class="hidden dark:block">
            <span>{{ config('product.name') }}</span>
        </a>
        <div class="flex items-center gap-3 sm:gap-6">
            <a href="{{ route('home') }}" class="auth-back"><x-ui.icon name="back"/><span>Tìm phòng</span></a>
            <label class="auth-theme"><span class="sr-only">Giao diện</span><select aria-label="Giao diện" x-model="theme" @change="applyTheme()"><option value="system">Theo hệ thống</option><option value="light">Sáng</option><option value="dark">Tối</option></select></label>
        </div>
    </header>
    <div class="auth-grid">
        <aside class="auth-story">
            <div class="auth-story-copy">
                <span class="auth-eyebrow"><span></span> {{ config('product.name') }} · Nơi bạn bắt đầu</span>
                <h2>@foreach(explode('\\n', config('identity_ui.headline')) as $line){{ $line }}@unless($loop->last)<br>@endunless @endforeach</h2>
                <p>{{ config('identity_ui.description') }}</p>
            </div>
            @include('product.identity-illustration')
            <ul class="auth-benefits">@foreach(config('identity_ui.benefits') as $benefit)<li><span><x-ui.icon name="check"/></span>{{ $benefit }}</li>@endforeach</ul>
        </aside>
        <section class="auth-panel" aria-labelledby="auth-title">
            <div class="auth-form-wrap">
                <div class="auth-heading"><span class="auth-kicker">{{ $register ? 'TẠO TÀI KHOẢN' : ($login ? 'TÀI KHOẢN CỦA BẠN' : 'KHÔI PHỤC TÀI KHOẢN') }}</span><h1 id="auth-title">{{ $copy['title'] }}</h1><p>{{ $copy['description'] }}</p></div>
                @if(session('status'))<div role="status" class="auth-feedback auth-feedback-success">{{ session('status') }}</div>@endif
                @if($errors->any())<div role="alert" class="auth-feedback auth-feedback-error">{{ $login ? 'Chưa thể đăng nhập. Bạn kiểm tra lại thông tin bên dưới nhé.' : 'Vui lòng kiểm tra lại các thông tin bên dưới.' }}@if($errors->has('token'))<p>{{ $errors->first('token') }}</p>@endif @if($errors->has('social'))<p>{{ $errors->first('social') }}</p>@endif</div>@endif
                @if(($login || $register) && config('email_auth.show_social_buttons'))
                    <div class="auth-socials">
                        @foreach(config('identity_ui.providers') as $feature => $provider)
                            @php($providerKey = str_replace('_login', '', $feature))
                            @if(app(\App\Modules\Identity\SocialLogin::class)->configured($providerKey))
                            <a class="auth-social" href="{{ route('social.redirect', ['provider' => $providerKey]) }}" x-data="{ leaving: false }" @click="if (leaving) $event.preventDefault(); leaving = true" :aria-disabled="leaving">
                                @include('product.social-mark', ['providerKey' => $feature])
                                <span x-show="!leaving">Tiếp tục với {{ $provider }}</span><span x-cloak x-show="leaving">Đang chuyển hướng…</span>
                            </a>
                            @else
                            <button type="button" data-development-feature="{{ $feature }}" class="auth-social" @click="notifyFeature('Đăng nhập bằng {{ $provider }} chưa sẵn sàng. Bạn vẫn có thể dùng email và mật khẩu.', 5000)">
                                @include('product.social-mark', ['providerKey' => $feature])
                                <span>Tiếp tục với {{ $provider }}</span>
                            </button>
                            @endif
                        @endforeach
                    </div>
                    <noscript><p class="auth-feedback">{{ config('availability.message') }} Bạn vẫn có thể sử dụng email bên dưới.</p></noscript>
                    <div class="auth-divider"><span>hoặc dùng email</span></div>
                @endif
                <form method="post" action="{{ $action }}" class="auth-form" x-data="identityForm" @submit="sending = true" @pageshow.window="sending = false">
                    @csrf
                    @if($register)
                        <div><label for="name">Họ và tên</label><input id="name" name="name" required maxlength="100" class="app-input" value="{{ old('name') }}" autocomplete="name" placeholder="Nhập họ và tên của bạn" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>@error('name')<p id="name-error" class="auth-field-error">{{ $message }}</p>@enderror</div>
                    @endif
                    <div><label for="email">Email</label><input id="email" name="email" type="email" inputmode="email" required maxlength="254" class="app-input" value="{{ old('email',request('email')) }}" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="ban@example.com" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>@error('email')<p id="email-error" class="auth-field-error">{{ $message }}</p>@enderror</div>
                    @if($login || $register || $reset)
                        <div><label for="password">{{ $reset ? 'Mật khẩu mới' : 'Mật khẩu' }}</label><div class="auth-password"><input id="password" name="password" type="password" :type="showPassword ? 'text' : 'password'" required @if(!$login) minlength="10" @endif class="app-input" autocomplete="{{ $login?'current-password':'new-password' }}" placeholder="{{ $login ? 'Nhập mật khẩu của bạn' : 'Ít nhất 10 ký tự' }}" @error('password') aria-invalid="true" @enderror aria-describedby="{{ $errors->has('password') ? 'password-error' : ($login ? '' : 'password-hint') }}"><button type="button" x-cloak @click="showPassword = !showPassword" :aria-label="showPassword ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'" :aria-pressed="showPassword"><x-ui.icon name="eye"/></button></div>@error('password')<p id="password-error" class="auth-field-error">{{ $message }}</p>@enderror @if(!$login)<p id="password-hint" class="auth-hint">Dùng ít nhất 10 ký tự để bảo vệ tài khoản.</p>@endif</div>
                        @if(!$login)
                            <div><label for="password_confirmation">Nhập lại mật khẩu</label><div class="auth-password"><input id="password_confirmation" name="password_confirmation" type="password" :type="showConfirmation ? 'text' : 'password'" required minlength="10" class="app-input" autocomplete="new-password" placeholder="Nhập lại mật khẩu vừa tạo" @error('password_confirmation') aria-invalid="true" aria-describedby="confirmation-error" @enderror><button type="button" x-cloak @click="showConfirmation = !showConfirmation" :aria-label="showConfirmation ? 'Ẩn mật khẩu nhập lại' : 'Hiện mật khẩu nhập lại'" :aria-pressed="showConfirmation"><x-ui.icon name="eye"/></button></div>@error('password_confirmation')<p id="confirmation-error" class="auth-field-error">{{ $message }}</p>@enderror</div>
                        @endif
                    @endif
                    @if($reset)<input type="hidden" name="token" value="{{ request('token') }}">@endif
                    @if($login)<div class="auth-form-options"><label class="auth-remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))>Ghi nhớ đăng nhập</label><a href="{{ route('ui.show',['slug'=>'recover-access','context'=>'public']) }}">Quên mật khẩu?</a></div>@endif
                    <button type="submit" class="app-button-primary auth-submit" :disabled="sending"><span x-show="!sending">{{ $copy['submit'] }}</span><span x-cloak x-show="sending">Đang xử lý…</span><x-ui.icon name="arrow"/></button>
                </form>
                <p class="auth-switch">@if($login)Bạn chưa có tài khoản? <a href="{{ route('ui.show',['slug'=>'register','context'=>'public']) }}">Đăng ký ngay</a>@elseif($register)Bạn đã có tài khoản? <a href="{{ route('login') }}">Đăng nhập</a>@else<a href="{{ route('login') }}">Quay lại đăng nhập</a>@endif</p>
            </div>
        </section>
    </div>
    <footer class="auth-footer"><span>© {{ date('Y') }} {{ config('product.name') }}</span><span>{{ config('product.tagline') }}</span></footer>
</div>
