# Kiểm chứng bản đóng gói — 12/11/2026

Đã build và chạy thực tế trên Docker Desktop / Linux containers, kiến trúc amd64. PHP 8.3.36, Laravel 12.69.2, MySQL 8.4.13; extension MongoDB 2.1.3. Các image đã được ghim digest trong Dockerfile và Compose.

| Nội dung | Kết quả |
|---|---|
| Docker Compose, Dockerfile, cài Composer theo lock và build Vite | PASS thực tế |
| Migration trên database Docker mới | PASS; không chạy lên HeThongTro/Laragon |
| Seeder + bộ TROHUB_DEMO_V1 và validator có sẵn | PASS, transaction hoàn tất; 314 tài khoản, 38 khu, 73 nhà, 434 phòng, 292 tin, 251 hồ sơ thuê, 901 hóa đơn |
| Tài khoản theo ngữ cảnh | PASS chín tài khoản; email đã xác minh, kiểm tra mật khẩu băm |
| Tìm trọ, CSS build và ảnh trong khung nhìn | PASS Chromium |
| Người thuê truy cập trang quản trị hệ thống | PASS: bị từ chối 403 |
| Đăng ký → hàng đợi auth-mail → Mailpit → OTP kích hoạt | PASS thực tế; cùng email có link và OTP. Không gửi Gmail thật |
| MongoDB / Redis / Cassandra / Neo4j / Meilisearch | PASS truy vấn native trên các container mới |
| Dữ liệu dẫn xuất ban đầu | Mongo: 150 hồ sơ sở thích, 40 đánh giá bổ sung; Meili: 191 tin công khai; đồ thị và lịch sử có dữ liệu đọc được |
| Rebuild và khởi động lại | PASS: marker, khóa ứng dụng và số lượng dữ liệu cốt lõi giữ nguyên, không seed trùng |
| Chạy nhẹ bằng launcher Windows | PASS: năm flag NoSQL tắt, tìm trọ HTTP 200, dữ liệu/khóa giữ nguyên |
| Chuyển lại đầy đủ bằng launcher Windows | PASS, kết nối lại năm dịch vụ, không nạp lại dữ liệu |
| Cú pháp PHP, PowerShell và shell runtime | PASS |
| Source nghiệp vụ so với repository | 607 file app/config/resources/routes/database/lang/tests/bootstrap giữ nguyên |

Bằng chứng JSON chi tiết được giữ trong bộ bàn giao local `TroHub-source.zip`; không đưa các file kết quả kiểm thử sinh tự động lên GitHub. Đây là kiểm tra bản Docker mới, không phải kết quả trên database làm việc. Sau kiểm thử đã dọn container và volumes thử của riêng project trohub-delivery; không xóa dữ liệu Laragon hay project Docker có sẵn.

## Giới hạn

- Không chạy lại toàn bộ PHPUnit trong lượt đóng gói vì không sửa nghiệp vụ. Đây là kiểm tra tích hợp vận hành, không tuyên bố đã kiểm thử mọi thao tác của hệ thống.
- Launcher Windows được chạy với tùy chọn NoBrowser để không bật cửa sổ ngoài ý muốn khi kiểm thử. Linux/macOS chưa chạy toàn bộ launcher; shell chỉ được kiểm tra cú pháp trong Linux container.
- Lần đầu cần Docker Engine đang chạy và Internet. Không đóng gói image offline hoặc database làm việc. Tài khoản đăng ký thêm trong bài kiểm tra chỉ tồn tại ở volumes thử đã dọn.
- Native init dựng schema/index, hồ sơ/đánh giá mẫu, đồ thị từ nhu cầu SQL, index tin công khai và đồng bộ 100 sự kiện đầu. Scheduler tiếp tục xử lý sự kiện còn chờ theo cơ chế hiện hành; không khẳng định mọi sự kiện đã chiếu ngay lúc khởi động.
- Redis là cache, có thể trống sau khi tạo lại container; được tái tạo từ dữ liệu chính. Các database và APP_KEY dùng volumes bền vững.
- Không có cấu hình Gmail, OAuth thật, tài khoản ngân hàng thật, phần cứng IoT hoặc Big Data trong bản Docker. Mailpit giữ thư nội bộ; chưa phải SMTP/EMAIL_RECEIVED_VERIFIED tại Gmail.
- Bản này khởi tạo từ seeder, không chứa các cập nhật thủ công, chứng từ và kỳ gói trong database hiện tại. Các gói/giá/quota vận hành phải được quản trị thiết lập theo chức năng có sẵn.
