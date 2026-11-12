<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><title>Lời mời tham gia · {{ config('product.name') }}</title>
<script>document.documentElement.classList.toggle('dark',localStorage.getItem('theme')==='dark'||((localStorage.getItem('theme')||'system')==='system'&&matchMedia('(prefers-color-scheme: dark)').matches));</script>
<style>:root { --app-primary: {{ config('ui.theme.primary') }}; --app-accent: {{ config('ui.theme.accent') }}; }</style>
@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body><main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center gap-6 px-4 py-10">
    <a class="flex items-center justify-center gap-3 text-xl font-bold" href="{{ route('home') }}"><img src="{{ config('product.logos.light') }}" width="40" height="40" alt="">{{ config('product.name') }}</a>
    <section class="app-card p-6 sm:p-8"><p class="people-eyebrow">Lời mời tham gia</p><h1 class="mt-3 text-2xl font-bold">{{ $workspace->name }}</h1><p class="mt-3 text-sm leading-6 text-slate-500">{{ $invite->purpose === 'tenant' ? 'Xác nhận để liên kết tài khoản với hồ sơ thuê của bạn.' : 'Xác nhận để tham gia quản lý cùng đội ngũ tại khu này.' }}</p>
    <dl class="my-6 space-y-4 text-sm"><div><dt class="text-slate-500">Tài khoản nhận lời mời</dt><dd class="mt-1 break-all font-semibold">{{ auth()->user()->email }}</dd></div><div><dt class="text-slate-500">Liên kết có hiệu lực đến</dt><dd class="mt-1 font-semibold">{{ $invite->expires_at->format('d/m/Y H:i') }}</dd></div></dl>
    @if($invite->purpose === 'staff')<div class="mb-6"><h2 class="text-sm font-semibold">Quyền được cấp</h2><ul class="mt-3 space-y-2 text-sm text-slate-500">@forelse($permissionNames as $name)<li>{{ $name }}</li>@empty<li>Chưa có quyền quản lý được cấp.</li>@endforelse</ul></div>@endif
    @if($errors->any())<div role="alert" class="people-feedback is-error mb-4">{{ $errors->first() }}</div>@endif
    @if(session('status'))<div role="status" class="people-feedback mb-4">{{ session('status') }}</div>@endif
    @php($state = $invitationState ?? 'pending')
    @if($state === 'pending')
    <form method="post" action="{{ $acceptUrl ?? url()->current() }}" x-data="{ sending:false }" @submit="sending=true">@csrf<input type="hidden" name="decision" value="accept"><button class="app-button-primary w-full" :disabled="sending" x-text="sending ? 'Đang xác nhận…' : 'Chấp nhận lời mời'">Chấp nhận lời mời</button></form>
    @if($canDecline ?? true)<details class="mt-4"><summary class="cursor-pointer text-center text-sm font-medium text-slate-500">Từ chối lời mời</summary><form method="post" action="{{ $declineUrl ?? route('invitation.respond',['invitation'=>$invite->id]) }}" class="mt-3" x-data="{ sending:false }" @submit="sending=true">@csrf<input type="hidden" name="decision" value="decline"><p class="mb-3 text-sm text-slate-500">Bạn sẽ không tham gia qua lời mời này. Người gửi sẽ thấy trạng thái đã từ chối.</p><button class="app-button-muted w-full" :disabled="sending">Xác nhận từ chối</button></form></details>@endif
    @else
    <div role="status" class="people-feedback">{{ ['accepted'=>'Bạn đã chấp nhận lời mời này.','declined'=>'Bạn đã từ chối lời mời này.','cancelled'=>'Người gửi đã hủy lời mời này.','expired'=>'Lời mời đã hết hạn. Hãy liên hệ người gửi nếu bạn muốn tham gia.'][$state] }}</div>
    @if(($membership ?? null)?->status === 'pending')<p class="mt-4 text-sm leading-6">Bạn đang chờ người quản lý kích hoạt quyền làm việc. Chấp nhận lời mời chưa cấp quyền truy cập khu quản lý.</p>@endif
    @endif
    <a class="mt-5 block text-center text-sm font-medium" href="{{ route('ui.show',['slug'=>'notifications','context'=>'personal']) }}">Về thông báo</a>
    <a class="mt-4 block text-center text-sm text-slate-500" href="{{ route('ui.show',['slug'=>'personal-dashboard','context'=>'personal']) }}">Để sau, về trang cá nhân</a>
    </section>
</main></body></html>
