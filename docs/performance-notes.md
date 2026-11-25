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

### Bằng chứng (Benchmark `make bench` — PHP 8.4.22, 500 routes, sorted by `Dyn ops/s` desc)

**1. OPcache ON (`opcache.enable_cli=1`, JIT `tracing`):**

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| Routir `-O2` (trước tối ưu) | 1.13 | 37.05 | 1.30 | 24,741 |
| **Routir (Compiled `-O2` match)** | 1.14 | **1.40** | **1.05** | **662,981** |
| Symfony Routing (`Compiled`) | 0.30 | 2.23 | 2.05 | 367,413 |
| **Routir (Compiled `-O2` PSR-7)** | 2.43 | 3.23 | 1.69 | 295,493 |
| FastRoute (`simpleDispatcher`) | 0.18 | 7.27 | 4.36 | 133,670 |
| FastRoute (`cachedDispatcher`) | **0.17** | 7.42 | 5.10 | 128,528 |
| League Route (PSR-7 dispatch) | 3.77 | 13.19 | 7.05 | 66,795 |
| Laravel Routing (`Compiled`) | 11.25 | 14.44 | 13,226.30 | 61,950 |

**2. OPcache OFF (`opcache.enable_cli=0`):**

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| **Routir (Compiled `-O2` match)** | 1.28 | **2.04** | 2.34 | **416,481** |
| Symfony Routing (`Compiled`) | 0.63 | 2.59 | 2.18 | 343,506 |
| **Routir (Compiled `-O2` PSR-7)** | 2.44 | 3.30 | **1.76** | 291,219 |
| League Route (PSR-7 dispatch) | 3.81 | 11.80 | 7.94 | 81,900 |
| FastRoute (`simpleDispatcher`) | **0.42** | 11.51 | 9.29 | 81,857 |
| FastRoute (`cachedDispatcher`) | 0.62 | 14.08 | 8.28 | 52,217 |
| Laravel Routing (`Compiled`) | 11.81 | 15.40 | 16,582.34 | 52,193 |

Kiểm chứng parity & chất lượng: `make qa` pass **86 tests (1901 assertions)** (bao gồm Differential O0/O1/O2, Golden, Fuzz) và **PHPStan Level 9 (0 errors)**.

---

## 2. Đợt tối ưu #2 — Interpreted Runtime Trie & Compiled Static Fast-Path (2026-11-25)

### Nguyên nhân gây chậm (Root Causes)

1. **Chế độ thông dịch (`ReferenceMatcher`) quét tuyến tính `O(N)` trên mọi request**
   - `ReferenceMatcher::matchForMethod()` duyệt tuần tự toàn bộ danh sách `$this->candidates` (500 phần tử), gọi `in_array($method, ...)`, gọi `matchCandidate()`, kiểm tra `count()`, và lặp lại vòng `foreach ($route->segments)` để tính `$defaults` trên mỗi lần match.
   - `collectAllowedMethods()` cũng lặp lại toàn bộ 500 candidates trên mỗi request 404.
   - Hệ quả: ở 500 routes, Interpreted mode chậm hơn cả `Laravel RouteCollection` (`64.47 µs` với OPcache ON, `246.97 µs` với OPcache OFF).

2. **Compiled `-O2` luôn chạy `PathNormalizer::normalize()` trước khi tra `STATIC_TABLES`**
   - Dù route tĩnh nằm sẵn trong `STATIC_TABLES[$method][$path]`, `PHPMatcherEmitter::match()` vẫn gọi `PathNormalizer::normalize()` (`explode` tạo mảng `$segments`) và `dispatchMethod()` trước khi kiểm tra bảng tĩnh.

### Giải pháp đã triển khai

- **[`ReferenceMatcher.php`](../src/Interpreter/ReferenceMatcher.php)**:
  - Precompute `$defaults` một lần duy nhất trong `__construct()` thay vì tính lại trên mỗi request.
  - Xây dựng `staticTables[$method][$path]` để tra cứu `O(1)` ngay đầu `match()` (kèm zero-alloc fast-path khi `!str_contains($requestTarget, '%')`).
  - Phân tách dynamic candidates theo `$method` và dựng **in-memory runtime trie** (gom bucket literal `O(1)` hash lookup + gom `param_group` liên tiếp), bảo toàn 100% thứ tự ưu tiên của `ReferenceMatcher`.
- **[`PHPMatcherEmitter.php`](../src/Backend/PHP/PHPMatcherEmitter.php)**:
  - Thêm zero-alloc fast-path tra trực tiếp `self::STATIC_TABLES[$method][$requestTarget]` (khi `!str_contains($requestTarget, '%')`) ngay đầu `match()`, bỏ qua hoàn toàn `PathNormalizer::normalize()` và `explode()` cho route tĩnh.

### Bằng chứng (Benchmark `make bench` — PHP 8.4.22, 500 routes, sorted by `Dyn ops/s` desc)

**1. OPcache ON (`opcache.enable_cli=1`, JIT `tracing`):**

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| **Routir (Compiled `-O2` match)** | **0.53** | **1.47** | **1.11** | **651,790** |
| **Routir (Interpreted match)** *(từ 14,505 →)* | **0.34** | **1.68** | **0.87** | **567,276** |
| Symfony Routing (`Compiled`) | 0.30 | 2.07 | 1.81 | 430,199 |
| **Routir (Compiled `-O2` PSR-7)** | 2.13 | 3.15 | 1.67 | 305,158 |
| FastRoute (`cachedDispatcher`) | **0.15** | 6.26 | 4.18 | 153,381 |
| FastRoute (`simpleDispatcher`) | 0.16 | 6.58 | 4.20 | 138,669 |
| League Route (PSR-7 dispatch) | 3.74 | 11.43 | 6.53 | 82,832 |
| Laravel Routing (`Compiled`) | 10.36 | 13.44 | — | 61,000+ |

**2. OPcache OFF (`opcache.enable_cli=0`):**

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| **Routir (Compiled `-O2` match)** | **0.45** | **1.54** | **1.19** | **596,432** |
| Symfony Routing (`Compiled`) | 0.64 | 2.63 | 2.21 | 347,248 |
| **Routir (Interpreted match)** *(từ 3,769 →)* | **0.47** | **3.13** | **1.52** | **231,623** |
| **Routir (Compiled `-O2` PSR-7)** | 2.19 | 3.76 | 2.23 | 214,053 |
| FastRoute (`cachedDispatcher`) | **0.26** | 7.60 | 5.31 | 125,873 |
| FastRoute (`simpleDispatcher`) | **0.26** | 7.73 | 5.24 | 120,799 |
| Laravel Routing (`Compiled`) | 11.41 | 14.84 | 18,134.55 | 58,881 |

Kiểm chứng parity & chất lượng: `make qa` pass **86 tests (1901 assertions)** (bao gồm Differential O0/O1/O2, Golden, Fuzz) và **PHPStan Level 9 (0 errors)**.

---

## 3. Đợt tối ưu #3 — O2 Segment-Count Specialization (2026-11-25)

### Giải pháp đã triển khai

- Đánh dấu riêng LIR sinh bởi `TrieFactoringPass` để emitter chỉ áp dụng specialization cho `-O2`.
- Với trie nhỏ, không có wildcard và có tối đa ba độ dài route chính xác:
  - Dispatch sớm bằng `$segCount`.
  - Partial-evaluate `ReadSegment` và `BranchEOF` theo độ dài đã biết.
  - Loại bỏ các `isset($segments[$i])`, EOF branch và basic block không thể tới trong từng lane.
- Giữ nguyên generic matcher cho wildcard, nhiều độ dài hoặc graph lớn để tránh phình artifact.
- Thay validator `strspn()` bằng `ctype_print()` + `strpbrk()` trên clean request target.
- Tách latency sampling khỏi throughput batch và đo generated matcher trực tiếp trong benchmark `match`.

### Kết quả

Benchmark chuẩn `make bench`, PHP 8.4.22:

| Routes / Mode | Routir O2 dyn p50 | Routir O2 dyn ops/s | Symfony dyn p50 | Symfony dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| 100, OPcache ON | **1.40 µs** | **732,540** | **1.40 µs** | 724,793 |
| 100, OPcache OFF | **0.88 µs** | **1,096,141** | 1.11 µs | 842,418 |
| 500, OPcache ON | **1.13 µs** | **917,244** | 2.06 µs | 472,358 |
| 500, OPcache OFF | **1.08 µs** | **901,828** | 2.19 µs | 376,509 |

Ở 100 routes, O2 đã tiệm cận hoặc vượt Symfony trong lượt benchmark đầy đủ và vượt mốc 1 triệu ops/s khi OPcache CLI tắt. Kết quả tuyệt đối dao động theo tải CPU; p50 và thứ hạng tương đối cần được đọc cùng nhau.

---

## 4. Backlog — Các điểm chưa tối ưu (Cơ hội tối ưu tiếp theo)

1. **Giảm cấp phát `MatchResult` trên đường dẫn `CompiledRouter::dispatch()`**
   - `CompiledRouter::dispatch()` gọi `$this->matcher->match(...)` tạo object `MatchResult`, sau đó đọc `routeId`/`params` rồi bỏ. Có thể cân nhắc internal fast method hoặc giữ `MatchResult` tối giản khi `dispatch()`.

2. **Tối ưu PSR-7 `CompiledRouter::dispatch()` (`1.5–2.7 µs`)**
   - `DefaultResponseAdapter` tạo `MinimalResponse` + `MinimalStream` qua `MinimalPsr17Factory` trên mỗi response string/array. Có thể tối ưu fast-path cho string response hoặc giảm số lần gọi factory trung gian.
