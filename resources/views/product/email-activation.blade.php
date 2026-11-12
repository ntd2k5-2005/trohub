@extends('layouts.app')
@section('content')
<div class="auth-shell">
    <header class="auth-header"><a class="auth-brand" href="{{ route('home') }}">{{ config('product.name') }}</a><label class="auth-theme"><span class="sr-only">Giao diện</span><select aria-label="Giao diện" x-model="theme" @change="applyTheme()"><option value="system">Theo hệ thống</option><option value="light">Sáng</option><option value="dark">Tối</option></select></label></header>
    <section class="app-card mx-auto w-full max-w-xl p-6 sm:p-8" aria-labelledby="activation-title">
        <h1 id="activation-title" class="text-2xl font-bold">Xác minh email</h1>
        @if(session('status'))<p role="status" class="auth-feedback auth-feedback-success">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="auth-feedback auth-feedback-error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(isset($challenge))
            <p class="mt-4 text-sm text-slate-500">Xác nhận để kích hoạt email. Nếu bạn đã xác minh bằng mã, liên kết này sẽ không còn hiệu lực.</p>
            <form method="post" action="{{ route('email.activation.confirm') }}" class="auth-form mt-5" x-data="{ sending: false }" @submit="sending = true">
                @csrf<input type="hidden" name="challenge" value="{{ $challenge }}"><input type="hidden" name="token" value="{{ $token }}">
                <button class="app-button-primary" :disabled="sending">Xác nhận email của tôi</button>
            </form>
        @else
            <p class="mt-4 text-sm text-slate-500">Xác minh <strong>{{ $email }}</strong> bằng liên kết hoặc mã trong cùng email. Chỉ cần hoàn tất một cách.</p>
            @if(($deliveryStatus ?? null) === 'failed')<p role="status" class="auth-feedback auth-feedback-error">Chưa gửi được email xác minh. Vui lòng chờ một phút rồi gửi lại.</p>
            @elseif(in_array($deliveryStatus ?? null, ['pending','sending'], true))<p role="status" class="auth-feedback">Yêu cầu gửi email đang được xử lý.</p>@endif
            <form method="post" action="{{ route('email.activation.otp') }}" class="auth-form mt-5" x-data="{ sending: false }" @submit="sending = true">
                @csrf<div><label for="activation-otp">Mã xác minh gồm 6 số</label><input id="activation-otp" name="otp" class="app-input" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"></div>
                <button class="app-button-primary" :disabled="sending">Xác minh và kích hoạt</button>
            </form>
            <form method="post" action="{{ route('email.activation.resend') }}" class="mt-4">@csrf<button class="app-button-muted">Gửi lại email xác minh</button></form>
            <p class="mt-4 text-sm text-slate-500">Nếu chưa thấy thư, hãy kiểm tra thư mục rác. Email gửi lại sẽ thay thế cả liên kết và mã cũ.</p>
        @endif
        <a class="app-button-ghost mt-5" href="{{ route('login') }}">Quay lại đăng nhập</a>
    </section>
</div>
@endsection
