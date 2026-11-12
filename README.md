# TrọHub — bản chạy độc lập bằng Docker

## Chạy trên Windows

1. Cài **Docker Desktop**, chọn Linux containers và mở Docker Desktop cho đến khi Engine chạy.
2. Chép nguyên thư mục này sang máy mới, vào thư mục và nhấn **CHAY.bat**.
3. Chờ cửa sổ báo hoàn tất. Website tự mở tại **http://localhost:8088**.

Lần đầu cần Internet để tải image, Composer và npm dependencies, build giao diện và khởi tạo dữ liệu. Các lần sau dùng lại image và dữ liệu đã lưu. Máy nhận không cần cài PHP, Node, Composer, MySQL hoặc Laragon.

Bản đầy đủ có MySQL, MongoDB, Redis, Cassandra, Neo4j, Meilisearch, Mailpit, ứng dụng, queue worker và scheduler. Nên dùng máy RAM 16 GB, cấp Docker khoảng 8 GB RAM và có ít nhất 12 GB dung lượng trống. Cassandra có thể cần vài phút khởi động.

Nếu chỉ cần xem nghiệp vụ trên máy ít RAM, nhấn **CHAY-NHE.bat**: ứng dụng + MySQL + Mailpit, các NoSQL tắt rõ ràng theo cấu hình và cơ chế của ứng dụng. Đây không phải chế độ nghiệm thu đủ năm NoSQL.

## Tài khoản có sẵn

Mật khẩu chung **Abcd@1234**, được băm bằng cơ chế hiện hành trước khi lưu. Những tài khoản seed được xác minh email; đăng ký mới vẫn phải xác minh bình thường.

| Email | Ngữ cảnh |
|---|---|
| supreme@trohub.test | Quản trị cấp cao |
| platform@trohub.test | Quản trị nền tảng |
| system@trohub.test | Quản trị hệ thống |
| owner@trohub.test | Chủ trọ |
| tenant@trohub.test | Người thuê |
| staff@trohub.test | Nhân viên vận hành |
| accountant@trohub.test | Nhân viên có nhóm quyền kế toán |
| maintenance@trohub.test | Nhân viên bảo trì |
| user@trohub.test | Người dùng cá nhân |

Database mới được dựng từ migration và các seeder hiện có, gồm bộ dữ liệu tổng hợp TROHUB_DEMO_V1. Không phải bản sao database làm việc hiện tại; các thay đổi thủ công của bạn, kỳ gói được tạo thêm và tài khoản ngân hàng thật không được mang sang. Không tự phát hành giá/quota hoặc nhận tiền thật. Có thể cấu hình gói và ngân hàng trên giao diện quản trị theo quyền.

## Email và tác vụ tự động

Mở **http://localhost:8025** để xem email xác minh, OTP và quên mật khẩu trong **Mailpit**. Email được giữ nội bộ; không gửi tới Gmail thật. Google/Facebook vẫn tắt và ẩn nút. Không có Gmail credentials trong bản bàn giao.

Queue worker xử lý cả hàng đợi `auth-mail` và `default`. Scheduler chỉ chạy bên trong container `scheduler`; không cài Windows Scheduled Task và không làm bật/tắt task Laragon của máy. Các tác vụ thời hạn và đồng bộ chỉ tác động database riêng của bản Docker.

## Dừng, mở lại và giữ dữ liệu

Nhấn **DUNG.bat** để dừng bản Docker. Mở lại bằng CHAY.bat hoặc CHAY-NHE.bat. Dữ liệu, ảnh, session và APP_KEY lưu trong Docker volumes riêng; dừng hoặc rebuild không tự seed đè dữ liệu.

Không dùng `docker compose down -v` nếu cần giữ dữ liệu: tùy chọn `-v` xóa volumes. Không xóa `.env` sau khi đã sử dụng database; file này chứa mật khẩu được tạo riêng trên máy nhận, cần khớp với volumes.

Các database không mở cổng ra máy chủ. Website và Mailpit chỉ bind loopback 127.0.0.1. Bản này dành cho trình diễn local/nội bộ; chưa phải cấu hình public Internet.

## Nếu máy đã dùng cổng 8088 hoặc 8025

Sau lần chạy đầu, chỉnh `APP_PORT` hoặc `MAIL_PORT` trong `.env` ở chính thư mục này, rồi chạy lại. Không sửa `.env` của dự án Laragon. Tất cả hostname database trong container là tên dịch vụ, không phải 127.0.0.1.

## Linux/macOS hoặc chạy bằng terminal

```sh
sh start.sh
# Máy ít RAM:
sh start.sh --light
```

Khi `.env` đã được tạo:

```sh
docker compose -f compose.yaml -f compose.full.yaml up -d --build --wait --wait-timeout 900
docker compose -f compose.yaml -f compose.full.yaml ps -a
docker compose -f compose.yaml -f compose.full.yaml logs --tail=100 init native-init app worker
docker compose -f compose.yaml -f compose.full.yaml down
```

Không cần npm/Vite/Composer trên host. Dockerfile build assets và cài dependencies theo lockfile bên trong image. Source Laravel đi kèm để chỉnh sửa và build lại; không bind thư mục source host vào runtime.

Nếu lần đầu bị mất mạng lúc build, chạy lại. Nếu seed báo database chưa có marker nhưng đã có người dùng, hệ thống dừng để bảo toàn dữ liệu, không tự reset. Xem log `init` và liên hệ người quản lý trước khi xóa bất kỳ volume nào.

## Phạm vi và bằng chứng

Ảnh nhà của bộ seed được đóng gói riêng; không mang theo ảnh upload cá nhân, log, cookie, .env thật, vendor hoặc node_modules. IoT chưa đi kèm phần cứng/máy ảo; Big Data không nằm trong Compose này. Xem `docs/DOCKER_VALIDATION.md` về những kiểm tra đã thực hiện và giới hạn.
