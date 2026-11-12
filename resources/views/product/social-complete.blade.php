@extends('layouts.app')
@section('content')
<div class="auth-shell">
    <header class="auth-header"><a class="auth-brand" href="{{ route('home') }}">{{ config('product.name') }}</a><label class="auth-theme"><span class="sr-only">Giao diện</span><select aria-label="Giao diện" x-model="theme" @change="applyTheme()"><option value="system">Theo hệ thống</option><option value="light">Sáng</option><option value="dark">Tối</option></select></label></header>
    <section class="app-card mx-auto w-full max-w-xl p-6 sm:p-8" aria-labelledby="social-complete-title">
        <h1 id="social-complete-title" class="text-2xl font-bold">Hoàn tất đăng nhập</h1>
        <p class="mt-3 text-sm text-slate-500">Bạn đã xác thực với {{ config('social_login.providers.'.$pending['identity']['provider'].'.label') }}. Hoàn tất bước bên dưới để tiếp tục.</p>
        @if(session('status'))<p role="status" class="auth-feedback auth-feedback-success">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="auth-feedback auth-feedback-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if($pending['phase'] === 'link')
            <p class="mt-4 text-sm">Email này đã có tài khoản. Xác nhận mật khẩu TrọHub để liên kết an toàn.</p>
            <form class="auth-form mt-4" method="post" action="{{ route('social.authenticate') }}" x-data="{ sending: false }" @submit="sending = true">
                @csrf
                <div><label for="link-email">Email tài khoản TrọHub</label><input id="link-email" class="app-input" type="email" name="email" required autocomplete="username" value="{{ $pending['email'] }}" readonly></div>
                <div><label for="link-password">Mật khẩu TrọHub</label><input id="link-password" class="app-input" type="password" name="password" required autocomplete="current-password"></div>
                <button class="app-button-primary" :disabled="sending">Xác nhận và liên kết</button>
                <a href="{{ route('ui.show', ['slug' => 'recover-access', 'context' => 'public', 'email' => $pending['email']]) }}">Quên hoặc chưa có mật khẩu?</a>
            </form>
        @else
            @if($pending['phase'] === 'verify')
                <p class="mt-4 text-sm">Nhập mã đã gửi đến {{ $pending['email'] }}.</p>
                <form class="auth-form mt-4" method="post" action="{{ route('social.verify') }}" x-data="{ sending: false }" @submit="sending = true">
                    @csrf
                    <div><label for="social-code">Mã xác minh gồm 6 số</label><input id="social-code" class="app-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
                    <button class="app-button-primary" :disabled="sending">Xác minh và tiếp tục</button>
                </form>
            @else
                <p class="mt-4 text-sm">Nhập email bạn đang sử dụng. Chúng tôi sẽ gửi mã để xác minh email trước khi tạo tài khoản.</p>
            @endif
            <form class="auth-form mt-4" method="post" action="{{ route('social.email') }}" x-data="{ sending: false }" @submit="sending = true">
                @csrf
                <div><label for="social-email">Email của bạn</label><input id="social-email" class="app-input" name="email" type="email" maxlength="254" autocomplete="email" value="{{ old('email', $pending['email']) }}" required></div>
                <button class="app-button-muted" :disabled="sending">{{ $pending['phase'] === 'verify' ? 'Gửi lại mã xác minh' : 'Tiếp tục với email này' }}</button>
            </form>
        @endif
        <form class="mt-5" method="post" action="{{ route('social.cancel') }}">@csrf<button class="app-button-ghost">Hủy và quay lại đăng nhập</button></form>
    </section>
</div>
@endsection
