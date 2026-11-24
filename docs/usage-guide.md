# Hướng dẫn sử dụng Routir

## 1. Cài đặt & Môi trường
Yêu cầu **PHP 8.4+** và **Composer 2**.

```bash
composer install
```

Hoặc dùng Docker để khởi tạo môi trường dev ngay:
```bash
docker compose up -d --build
docker compose exec dev composer install
```

---

## 2. Định nghĩa Routes
Tạo file `routes.php` trả về closure có typehint `Router $router` để IDE gợi ý đầy đủ và không báo lỗi biến chưa khai báo (xem [examples/routes.php](file:///home/long/Dev/router/examples/routes.php)):

```php
<?php
declare(strict_types=1);

use Routir\API\Router;

return static function (Router $router): void {
    $router->get('/', static fn (): string => 'OK')->name('home');
    $router->get('/users/{id:int}', static fn (int $id): string => "User #$id")->name('users.show');

    // Group với prefix, name prefix, middleware
    $router->prefix('/api')->name('api.')->middleware(['json', 'auth'])->group(function (Router $r): void {
        $r->get('/posts/{slug:slug}', static fn (string $slug): string => "Post: $slug")
            ->name('posts.show');
        $r->get('/health', static fn (): string => 'healthy')
            ->withoutMiddleware('auth')
            ->name('health');
    });

    // Fallback cho 404 (không áp dụng cho 400 / 405)
    $router->fallback(static fn (): string => 'Page not found');
};
```

### Các phương thức & Ràng buộc hỗ trợ
- **HTTP methods**: `get`, `post`, `put`, `patch`, `delete`, `options`, `head`, `match(['GET', 'POST'], ...)`, `any`.
- **Inline constraints**:
  - `{id:int}`: chỉ chữ số ASCII `[0-9]+`
  - `{name:alpha}`: chỉ chữ cái ASCII `[a-zA-Z]+`
  - `{code:alnum}`: chữ và số ASCII `[a-zA-Z0-9]+`
  - `{slug:slug}`: các nhóm `alnum` nối bởi dấu `-`
  - `{uuid:uuid}`: UUID chuẩn `8-4-4-4-12`
  - `{path:*}`: wildcard (bắt buộc là segment cuối, không rỗng, không optional)
- **Optional & Defaults**: `{page:int?}` kèm `->defaults('page', '1')` (chỉ áp dụng ở phần đuôi liên tiếp).
- **Fluent constraints**: `->where('code', 'ORD-[0-9]{4}')`, `->whereNumber('id')`, `->whereAlpha('name')`, `->whereUuid('id')`, `->whereIn('env', ['dev', 'prod'])`.

---

## 3. Chế độ Interpreted vs. Compiled

### Interpreted Mode (phù hợp lúc dev)
```php
use Routir\API\RouterFactory;
use Routir\Contracts\RouterMode;

$router = RouterFactory::create(
    mode: RouterMode::Interpreted,
    routes: __DIR__ . '/routes.php',
    container: $container,
);

$response = $router->dispatch($psr7Request);
$url = $router->route('users.show', ['id' => 42]); // "/users/42"
```

### Compiled Mode (phù hợp production)
Biên dịch trước ở bước build/CI:
```bash
php bin/routir build examples/routes.php --output=var/routir -O2
```
Load artifact ở runtime (không nạp compiler, không parse pattern, không reflection):
```php
$router = RouterFactory::create(
    mode: RouterMode::Compiled,
    artifact: __DIR__ . '/var/routir',
    container: $container,
);
```

### Cấu hình vị trí Input & Output (`CompileConfig`)
Routir hỗ trợ cấu hình **1 file**, **nhiều file**, hoặc **cả thư mục** chứa các file `*.php` cho đầu vào (`input`/`routes`) và chỉ định thư mục xuất artifact (`output`/`artifact`):

```php
use Routir\API\RouterFactory;
use Routir\Compiler\CompileConfig;
use Routir\Compiler\Compiler;
use Routir\Contracts\RouterMode;

$config = new CompileConfig(
    inputs: [__DIR__ . '/routes/web.php', __DIR__ . '/routes/api'], // file hoặc thư mục
    outputDir: __DIR__ . '/var/routir',
    mode: RouterMode::Auto,
    optimizationLevel: 2,
);

// Build ra outputDir
new Compiler()->compile($config);

// Khởi tạo router từ config
$router = RouterFactory::fromConfig($config, container: $container);
```

Hoặc tạo file `routir.php` ở root và chạy CLI không cần lặp tham số:
```php
<?php
return [
    'input' => [__DIR__ . '/routes/web.php', __DIR__ . '/routes/api'],
    'output' => __DIR__ . '/var/routir',
    'mode' => 'auto',
    'optimizationLevel' => 2,
];
```
```bash
php bin/routir build --config=routir.php
# hoặc truyền trực tiếp:
php bin/routir build --input=routes/web.php,routes/api --output=var/routir -O2
```

### Auto Mode
Dùng `RouterMode::Auto` với cả `routes` và `artifact`: nếu `artifact` hợp lệ sẽ load bản compiled, ngược lại tự fallback về interpreted.

---

## 4. Middleware PSR-15 & Dependency Injection (PSR-11)
- Đăng ký alias: `$router->middlewareAlias('auth', RoleMiddleware::class);`
- Truyền tham số cho middleware: `->middleware('auth:admin')` (tách theo `,` sau dấu `:`).
- Loại trừ middleware kế thừa từ group: `->withoutMiddleware('auth')`.
- Thứ tự bind tham số cho handler:
  1. `->bind('param', $value)`
  2. Route capture (`{id}` -> ép kiểu `int`/`float`/`bool` nếu khai báo typehint)
  3. `Psr\Http\Message\ServerRequestInterface`
  4. Service từ PSR-11 `ContainerInterface` (theo class/interface typehint)
  5. Giá trị mặc định của tham số PHP

---

## 5. Custom Response Adapter
Mặc định `DefaultResponseAdapter` nhận `ResponseInterface` hoặc `string`. Để trả về `array` / DTO (ví dụ tự động convert thành JSON), truyền `ResponseAdapterInterface` vào `Router` hoặc `RouterFactory::create(responseAdapter: ...)`. Xem [examples/bootstrap.php](file:///home/long/Dev/router/examples/bootstrap.php).

---

## 6. Chạy thử bộ Examples
Cả 2 example dùng chung [examples/routes.php](file:///home/long/Dev/router/examples/routes.php):
```bash
php examples/01-interpreted/run.php
php examples/02-compiled/run.php
```
