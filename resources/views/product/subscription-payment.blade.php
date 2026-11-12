@php($quote = $subscriptionPayment['quote'])
<article class="app-card p-5" x-data="paymentCard">
    <h2 class="text-lg font-bold">{{ $quote->planVersion->snapshot['label'] }}</h2>
    <p class="mt-2 text-sm text-slate-500">{{ $quote->status === 'cancelled' ? 'Đã hủy yêu cầu' : ($quote->status === 'confirmed' ? 'Đã xác nhận thực nhận' : ($quote->payer_reported_at ? 'Chờ xác nhận thực nhận' : 'Chưa gửi thông tin chuyển khoản')) }}</p>
    <p class="mt-3 text-2xl font-bold">{{ \App\Support\ProductPresenter::money($quote->amount) }}</p>
    <p class="mt-2 text-sm">Hạn thanh toán: {{ $quote->expires_at->format('d/m/Y H:i') }}</p>
    @if($quote->status === 'pending')<p class="mt-2 text-sm">Dự kiến bắt đầu: {{ $subscriptionPayment['proposed_start']->format('d/m/Y H:i') }}. Thời hạn chính thức được chốt khi xác nhận thực nhận.</p>@endif
    @if($subscriptionPayment['payable'])
        @php($payee = $quote->payee_snapshot)
        @php($qrUrl = route('subscription-payment.qr', ['quote'=>$quote->id,'context'=>'personal']))
        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-semibold">Xem mã QR và thông tin chuyển khoản</summary>
            <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,180px)_minmax(0,1fr)]">
                <div><img class="w-full rounded-xl bg-white" src="{{ $qrUrl }}" alt="Mã QR thanh toán gói" loading="lazy" x-on:error="qrFailed = true; $el.hidden = true"><p x-cloak x-show="qrFailed" class="text-sm">Chưa tải được mã QR. Bạn có thể dùng thông tin chuyển khoản hoặc thử lại sau.</p><a class="app-button-muted mt-3" x-show="!qrFailed" href="{{ $qrUrl.'&download=1' }}">Tải ảnh QR</a></div>
                <dl class="housing-facts">@foreach(['Ngân hàng'=>$payee['bank_name'],'Người nhận'=>$payee['account_holder_name'],'Số tài khoản'=>$payee['account_no'],'Nội dung'=>$quote->transfer_reference] as $label=>$value)<div><dt>{{ $label }}</dt><dd class="break-all">{{ $value }}</dd></div>@endforeach</dl>
            </div>
            <div class="mt-4 flex flex-wrap gap-2"><button type="button" class="app-button-muted" @click="copy(@js($payee['account_no']))">Sao chép tài khoản</button><button type="button" class="app-button-muted" @click="copy(@js((string)$quote->amount))">Sao chép số tiền</button><button type="button" class="app-button-muted" @click="copy(@js($quote->transfer_reference))">Sao chép nội dung</button></div>
            <p class="mt-2 text-sm" role="status" x-text="message"></p>
            <p class="mt-3 text-sm text-slate-500">Kiểm tra đúng số tiền và người nhận trong ứng dụng ngân hàng trước khi chuyển. Mã QR không phải biên nhận.</p>
        </details>
        @unless($quote->payer_reported_at)
        <details class="mt-4"><summary class="cursor-pointer font-semibold">Tôi đã chuyển</summary>
            <form method="post" class="mt-4 space-y-4" action="{{ route('action',['action'=>'account.payment.report']) }}">
                @csrf<input type="hidden" name="context" value="personal"><input type="hidden" name="id" value="{{ $quote->id }}">
                <label class="block text-sm">Mã giao dịch ngân hàng<input name="reference" class="app-input mt-1" required maxlength="100"></label>
                <label class="block text-sm">Lời nhắn<textarea name="note" class="app-input mt-1" maxlength="1000"></textarea></label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-1">Tôi đã thực hiện chuyển khoản theo thông tin trên</label>
                <button class="app-button-primary">Tôi đã chuyển</button>
            </form>
        </details>
        @endunless
    @elseif(!in_array($quote->status, ['confirmed', 'cancelled'], true))
        <p class="mt-4 text-sm text-amber-700 dark:text-amber-300">Yêu cầu đã thay đổi, hết hạn hoặc cần kiểm tra thêm. Vui lòng không chuyển theo mã cũ. Nếu đã chuyển tiền, hãy liên hệ hỗ trợ để đối soát.</p>
    @endif
    @if($quote->status === 'pending' && !$quote->payer_reported_at)
    <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Hủy yêu cầu chưa thanh toán</summary>
        <form method="post" class="mt-4 space-y-4" action="{{ route('action', ['action'=>'account.quote.cancel']) }}">
            @csrf<input type="hidden" name="context" value="personal"><input type="hidden" name="id" value="{{ $quote->id }}">
            <label class="block text-sm">Lý do hủy<textarea name="reason" class="app-input mt-1" required maxlength="1000"></textarea></label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required>Tôi chưa chuyển tiền cho yêu cầu này</label>
            <button class="app-button-muted">Hủy yêu cầu</button>
        </form>
    </details>
    @endif
    @if($quote->cancelled_at)<p class="mt-3 break-words text-sm">Đã hủy lúc {{ $quote->cancelled_at->format('d/m/Y H:i') }} · {{ $quote->cancellation_reason }}</p>@endif
    @if($quote->payer_reported_at)<p class="mt-4 break-words text-sm">Đã gửi thông tin: {{ $quote->payer_bank_reference }} · {{ $quote->payer_reported_at->format('d/m/Y H:i') }}</p>@endif
</article>
