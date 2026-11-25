# Performance Notes & Optimization Log

Tài liệu này ghi lại các phân tích nguyên nhân gây chậm, giải pháp đã triển khai, bằng chứng đo đạc (benchmark) và danh sách các điểm có thể tiếp tục tối ưu trong tương lai.

---

## 1. Đợt tối ưu #1 — Compiled `-O2` Trie Dispatch & Hot-Path (2026-11-25)

### Nguyên nhân gây chậm (Root Causes)

1. **Phân nhóm Trie bị vỡ & quét tuyến tính `O(N)` trong `TrieFactoringPass`**
   - `MIRLowering` sắp xếp các route cùng specificity bằng `registrationOrder`. Khi các prefix literal xen kẽ nhau (ví dụ: `/api/v1/resource{i}/{id}` và `/api/v1/org/{org}/repo{i}/{slug}`), `TrieFactoringPass` trước đây chỉ gom các candidate **liền kề giống hệt nhau**, dẫn đến nhánh `org` bị tách thành 166 lần kiểm tra riêng lẻ.
   - Các nhánh có nhiều literal khác nhau ở cùng độ sâu (ví dụ `repo2`, `repo5`, ..., `repo497`) phát sinh chuỗi `if ($seg === ...) goto ...` tuyến tính `O(N)`.
   - Các đoạn parameter giống hệt nhau (ví dụ `{org:alpha}`) không được gom chung, khiến cùng một constraint bị kiểm tra lặp lại nhiều lần.

2. **Chi phí chuẩn hóa đường dẫn cao trong `PathNormalizer::normalize()`**
   - Mọi request (kể cả URL ASCII sạch không có `%`, `?`, `#`, `\`) đều phải chạy `preg_match` kiểm tra control byte, sau đó lặp từng segment để chạy `rawurldecode()`, `preg_match()`, `str_contains()` và `mb_check_encoding(..., 'UTF-8')`.

3. **Sinh mã chưa tối ưu trong `PHPMatcherEmitter`**
   - Luôn chạy `implode('/', $segments)` để tạo `$rawPath` trên mọi request, kể cả khi `$requestTarget` không chứa `%`, `?`, `#`.
   - Gọi hàm tĩnh `ConstraintRegistry::matchesBuiltin()` kèm `match ($name)` cho mọi constraint cơ bản (`int`, `alpha`, `alnum`, `slug`, `uuid`).

4. **Cấp phát object thừa trong `CompiledRouter::dispatchMatched()`**
   - Luôn khởi tạo anonymous class `RequestHandlerInterface` (`$terminal`) trên mọi request, kể cả khi route không có middleware nào (`$mwList === []`).

### Giải pháp đã triển khai

- **[`TrieFactoringPass.php`](../src/Transform/Optimization/TrieFactoringPass.php) & [`SwitchLiteral.php`](../src/IR/LIR/SwitchLiteral.php)**:
  - Gom bucket theo giá trị literal trong toàn bộ dải literal liên tiếp (bảo toàn thứ tự tương đối trong từng bucket, giữ nguyên 100% ngữ nghĩa vì các literal khác nhau loại trừ lẫn nhau).
  - Thêm LIR instruction `SwitchLiteral` phát sinh PHP `switch ($seg)` (hash table `O(1)` trong Zend Engine) cho các literal non-numeric; tách riêng literal numeric sang `===` để tránh loose comparison (`'01' == '1'`) của PHP `switch`.
  - Gom các đoạn `param` liên tiếp có cùng tên & constraint signature; trì hoãn ghi `$captures` đến khi match thành công để tránh chi phí ghi/xóa mảng trên nhánh fail.
- **[`PathNormalizer.php`](../src/Runtime/PathNormalizer.php)**:
  - Dùng `strcspn($requestTarget, '?#')` để tách query/fragment và thêm fast-path `strspn()` cho đường dẫn ASCII URL-safe, bỏ qua `preg_match`, `rawurldecode` và `mb_check_encoding` khi không cần thiết.
- **[`PHPMatcherEmitter.php`](../src/Backend/PHP/PHPMatcherEmitter.php)**:
  - Tái sử dụng trực tiếp `$requestTarget` làm `$rawPath` khi không có `%`, `?`, `#`.
  - Inline trực tiếp điều kiện cho builtin constraints (`ctype_digit`, `ctype_alpha`, `ctype_alnum`, `preg_match` cho `slug`/`uuid`).
- **[`CompiledRouter.php`](../src/Runtime/CompiledRouter.php)**:
  - Bỏ qua bước tạo anonymous `RequestHandlerInterface` khi `$mwList === []`, gọi thẳng `$this->handlers->invoke(...)`.

### Bằng chứng (Benchmark `make bench-compare` — PHP 8.4.22, OPcache yes, JIT tracing)

**500 routes (1/3 static, 1/3 int param, 1/3 multi-param | worst-case dynamic index = 497):**

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| Routir `-O2` (trước tối ưu) | 1.13 | 37.05 | 1.30 | 24,741 |
| **Routir `-O2` (sau tối ưu)** | **0.61** | **0.97** | **0.76** | **995,066** |
| FastRoute (`cachedDispatcher`) | 0.11 | 4.58 | 3.20 | 197,941 |
| Symfony Routing (`Compiled`) | 0.53 | 2.11 | 1.57 | 362,485 |
| Laravel Routing (`Compiled`) | 11.26 | 14.45 | 15,030.79 | 58,607 |

**Full PSR-7 Dispatch (500 routes):**
- `Routir (Compiled -O2 PSR-7)`: Static **3.00 µs** | Dyn p50 **3.87 µs** | Miss p50 **2.02 µs** | **240,087 ops/s**
- `League Route (PSR-7 dispatch)`: Static **4.84 µs** | Dyn p50 **15.63 µs** | Miss p50 **9.96 µs** | **47,940 ops/s**

Kiểm chứng parity & chất lượng: `make qa` pass **86 tests (1901 assertions)** (bao gồm Differential O0/O1/O2, Golden, Fuzz) và **PHPStan Level 9 (0 errors)**.

---

## 2. Backlog — Các điểm chưa tối ưu (Cơ hội tối ưu tiếp theo)

1. **Static route fast-path trước `PathNormalizer::normalize()` (`Static: 0.61 µs` vs FastRoute `0.11 µs`)**
   - Hiện tại `PHPMatcherEmitter::match()` luôn chạy `PathNormalizer::normalize()` (`explode` tạo mảng `$segments`) trước khi tra `STATIC_TABLES[$method][$rawPath]`.
   - Nếu `STATIC_TABLES[$method][$requestTarget]` hit ngay khi `$requestTarget` là đường dẫn sạch (không `?`, `#`, `%`, `\`, control byte, `/./`, `/../`), có thể trả về `MatchResult` ngay lập tức mà **không cần `explode()` tạo mảng `$segments`**.

2. **Giảm cấp phát `MatchResult` trên đường dẫn `CompiledRouter::dispatch()`**
   - `CompiledRouter::dispatch()` gọi `$this->matcher->match(...)` tạo object `MatchResult`, sau đó đọc `routeId`/`params` rồi bỏ. Có thể cân nhắc internal fast method hoặc giữ `MatchResult` tối giản khi `dispatch()`.

3. **Tối ưu PSR-7 `CompiledRouter::dispatch()` (`3.00–3.87 µs`)**
   - `DefaultResponseAdapter` tạo `MinimalResponse` + `MinimalStream` qua `MinimalPsr17Factory` trên mỗi response string/array. Có thể tối ưu fast-path cho string response hoặc giảm số lần gọi factory trung gian.

4. **Tối ưu `-O1` / non-contiguous literal sorting khi độ đặc hiệu bằng nhau**
   - Hiện tại `MIRLowering` giữ nguyên `registrationOrder` giữa các route khác prefix ở cùng độ sâu. Với các route có **nhiều param nối tiếp khác nhau** hoặc **optional segments xen kẽ**, có thể nghiên cứu radix/prefix merging sâu hơn ở cấp MIR/LIR mà vẫn bảo toàn 100% precedence.
