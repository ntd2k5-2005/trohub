<!doctype html><html lang="vi"><meta charset="utf-8"><body style="font-family:Arial,sans-serif;color:#16233b;line-height:1.7;padding:24px">
<h1 style="font-size:24px">{{ config('product.name') }}</h1>
<p>Xin chào {{ $details['name'] }},</p>
@if($details['purpose'] === 'activation')
<h2>Xác minh email của bạn</h2><p>Bạn có thể dùng <strong>một trong hai cách</strong> dưới đây để kích hoạt email. Sau khi xác minh thành công, cả liên kết và mã đều hết hiệu lực.</p>
<p><a href="{{ $details['url'] }}" style="display:inline-block;background:#6256df;color:white;padding:12px 20px;border-radius:10px;text-decoration:none">Xác minh email</a></p>
<p>Hoặc nhập mã tại màn hình xác minh:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">{{ $details['otp'] }}</p>
@else
<h2>Khôi phục mật khẩu</h2><p>Bạn vừa yêu cầu thiết lập lại mật khẩu. Liên kết chỉ dùng được một lần.</p>
<p><a href="{{ $details['url'] }}" style="display:inline-block;background:#6256df;color:white;padding:12px 20px;border-radius:10px;text-decoration:none">Đặt lại mật khẩu</a></p>
@endif
<p>Yêu cầu có hiệu lực đến {{ $details['expires_label'] }}. Không chia sẻ liên kết hoặc mã với người khác.</p>
<p>Nếu bạn không gửi yêu cầu này, hãy bỏ qua email. Không ai có thể đăng nhập chỉ bằng việc yêu cầu gửi thư.</p>
</body></html>
