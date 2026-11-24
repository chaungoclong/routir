# AGENTS.md — Hướng dẫn phát triển cho Coding Agents

Tài liệu này quy định chuẩn mực viết code, kiến trúc và quy trình kiểm thử khi làm việc trên dự án **Routir**.

---

## 1. Kiến trúc & Nguyên tắc cốt lõi
- **Target**: PHP **8.4+**, bắt buộc `declare(strict_types=1);` ở đầu mọi file PHP.
- **PSR-4**:
  - `Routir\` → `src/`
  - `Routir\Tests\` → `tests/`
- **Ranh giới phụ thuộc**:
  - `src/Runtime/`, `src/Middleware/`, `src/Contracts/`, `src/Exception/` **không được** phụ thuộc vào `src/Compiler/`, `src/Frontend/`, `src/IR/`, `src/Semantic/`, hay `nikic/php-parser`.
- **Parity**: Mọi thay đổi về matching, handler binding, middleware hoặc reverse routing phải giữ ngữ nghĩa quan sát được giống hệt nhau giữa **Interpreted** và **Compiled** (`-O0`, `-O1`, `-O2`).

---

## 2. Chuẩn Code (PSR-12 & PHP 8.4+)
- **PSR-12 & Imports**:
  - Thụt lề 4 khoảng trắng, không tab, dòng kết thúc bằng LF.
  - Sắp xếp `use` gọn gàng, không để import thừa.
  - **Luôn import thay vì dùng FQN/global prefix inline** (tránh cảnh báo IDE *"Qualifier can be replaced with an import"*): `use Closure;`, `use Throwable;`, `use RuntimeException;`, `use ReflectionClass;`, `use Stringable;`, v.v. ở đầu file thay vì `\Closure`, `\Throwable`, `\Routir\...` trong code hoặc PHPDoc.
  - Khai báo `strict_types=1` ngay sau `<?php` với 1 dòng trống ngăn cách.
- **Tận dụng tính năng PHP 8.4+**:
  - Dùng `new ClassName()->method()` trực tiếp, **không** bọc `(new ClassName())->method()`.
  - **Luôn dùng `final` và `readonly` khi có thể**: mọi class không thiết kế để kế thừa phải khai báo `final` (và `final readonly class` nếu bất biến); mọi property không thay đổi sau khởi tạo phải khai báo `readonly`.
  - Ưu tiên `enum`, `match`, constructor property promotion, typed class constants (`public const string ...`), `#[\Override]`.
  - Luôn khai báo explicit nullable (`?Type $param = null`), tuyệt đối không dùng implicit nullable đã deprecated ở PHP 8.4.
  - Sử dụng các hàm mới của PHP 8.4 (`array_find()`, `array_any()`, `array_all()`, v.v.) khi giúp code ngắn gọn và rõ nghĩa hơn.
  - Ưu tiên `array_map()` / `array_filter()` thay cho vòng lặp `foreach` chỉ dùng để biến đổi hoặc lọc từng phần tử sang mảng mới (tránh cảnh báo IDE *"Loop can be converted to an 'array_map()' call"*).
- **Named Parameters**:
  - Khi gọi hàm, method hoặc constructor có **từ 3 tham số trở lên**, hoặc có tham số `bool` / `null` dễ gây nhầm nghĩa tại call site, **bắt buộc dùng named arguments**:
    ```php
    $router = RouterFactory::create(
        mode: RouterMode::Compiled,
        artifact: $artifactDir,
        container: $container,
    );
    ```

- **Control Flow & Khả năng phân tích tĩnh**:
  - Không đặt câu lệnh `return` duy nhất nằm lồng bên trong khối `try { ... } finally { ... }` nếu thiếu `return` ở cuối hàm (tránh cảnh báo IDE *"Missing 'return' statement"*); hãy gán kết quả trong `try` và `return` sau khối `finally`.

---

## 3. Quy chuẩn Docblock (Bắt buộc)
Mọi class, interface, enum, method, function và property có kiểu phức tạp **phải có PHPDoc** đầy đủ:
- **Description**: dòng mô tả ngắn gọn chức năng/mục đích của thành phần.
- **`@param`**: ghi đầy đủ tên biến khớp chính xác với signature và kiểu chi tiết (ưu tiên `list<T>`, `array<string, mixed>`, `callable(...)`, v.v.) kèm mô tả ngắn khi không tự hiển nhiên.
- **`@return`**: ghi rõ kiểu trả về (kể cả cấu trúc array shape nếu có).
- **`@throws` (Đầy đủ cả trực tiếp lẫn bắc cầu / transitive)**: liệt kê **tất cả** mọi exception có thể ném ra trong thân hàm, bao gồm:
  - Exception ném trực tiếp bằng `throw`.
  - Exception bắc cầu từ các hàm/method được gọi nhưng không `catch` (kể cả `JsonException` khi dùng `JSON_THROW_ON_ERROR`, `RandomException` khi dùng `random_bytes()`, `ReflectionException`, `LogicException`, `ContainerExceptionInterface`, `NotFoundExceptionInterface`, v.v.).
  - Không khai báo thừa `@throws` hoặc `@param` cho nhầm method (ví dụ constructor của anonymous class), phải kiểm tra bằng `phpstan analyse` ở level 9.

Ví dụ chuẩn:
```php
/**
 * Compile route definitions from a configuration into PHP artifacts.
 *
 * @param CompileConfig $config Input paths, output directory, and optimization options.
 *
 * @return array{buildId: string, releaseDir: string, context: CompilationContext}
 *
 * @throws CompilationException When route compilation or IR verification fails.
 * @throws RoutirException When output directory locking or file publishing fails.
 */
public function compile(CompileConfig $config): array
```

---

## 4. Workflow, Test & Quality Gates
- **Workflow bắt buộc khi sửa code**:
  1. Đọc kỹ phần liên quan và tuân thủ các chuẩn ở mục 1–3.
  2. Thực hiện chỉnh sửa gọn, không lặp logic.
  3. **Bắt buộc test lại sau khi sửa xong**: luôn chạy lại toàn bộ kiểm tra bên dưới sau lần chỉnh sửa cuối cùng trước khi báo cáo kết quả cho người dùng.
- **Framework test**: Sử dụng **Pest** (`tests/`), viết test bằng `test(...)` / `it(...)`, `expect(...)`, và `->with(...)` cho datasets. Không viết class PHPUnit truyền thống cho test mới.
- **Kiểm tra bắt buộc trước khi kết thúc task**:
  1. Chạy toàn bộ test suite:
     ```bash
     vendor/bin/pest
     ```
  2. Chạy kiểm tra kiểu tĩnh PHPStan level 9 (0 errors):
     ```bash
     vendor/bin/phpstan analyse --no-progress
     ```
  3. Nếu thay đổi liên quan đến compiler, runtime hoặc examples, chạy kiểm chứng:
     ```bash
     php examples/01-interpreted/run.php
     php examples/02-compiled/run.php
     php bin/routir check examples/routes.php
     ```
- Không sửa/bỏ qua assertion để làm test pass giả tạo; chỉ báo cáo pass khi đã chạy thực tế sau lần chỉnh sửa cuối cùng.
