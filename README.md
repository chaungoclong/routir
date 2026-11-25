<p align="center">
  <img src="docs/assets/routir-banner.png" alt="Routir — Compile once, execute many — AOT HTTP Router for PHP 8.4+" width="100%">
</p>

# Routir

> **Compile once, execute many, reset every request.**

[![CI](https://github.com/chaungoclong/routir/actions/workflows/ci.yml/badge.svg)](https://github.com/chaungoclong/routir/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-Level%209-brightgreen)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/Tests-Pest%203-F55247)](https://pestphp.com/)
[![PSR](https://img.shields.io/badge/PSR-7%20%7C%2011%20%7C%2015%20%7C%2017-blue)](https://www.php-fig.org/psr/)
[![License](https://img.shields.io/badge/License-MIT-green)](#license)

**Routir** is a framework-agnostic, Laravel-inspired HTTP router for **PHP 8.4+** with **Interpreted** (development) and **Ahead-of-Time (AOT) Compiled** (production) execution modes.

Instead of parsing route patterns, building tries, or reflecting handlers on every PHP-FPM request, Routir moves routing computation to build time and emits clean, deterministic, OPcache-friendly PHP code — while preserving **100% identical observable semantics** across both modes.

---

## Table of Contents

- [Why Routir?](#why-routir)
- [Features](#features)
- [Requirements & Installation](#requirements--installation)
- [Quick Start](#quick-start)
- [Configuration (`CompileConfig`)](#configuration-compileconfig)
- [CLI Reference](#cli-reference)
- [Compiler Architecture](#compiler-architecture)
- [Examples](#examples)
- [Benchmarks](#benchmarks)
- [Testing & Quality Gates](#testing--quality-gates)
- [Docker Dev Environment](#docker-dev-environment)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)

---

## Why Routir?

| Aspect | Interpreted Mode (Dev) | Compiled Mode (Prod `-O2`) |
| --- | --- | --- |
| **Route Registration** | Executes route files at boot | None (pre-compiled artifacts only) |
| **Pattern Parsing** | Lexer + Recursive Descent Parser | None (lowered to PHP decision graph) |
| **Handler Reflection** | At router freeze time | None (direct calls & AST-extracted closures) |
| **Static Dispatch** | Sorted candidate matching | `O(1)` hash table lookup (`STATIC_TABLES`) |
| **Dynamic Dispatch** | Reference trie/candidate matcher | Factored prefix-tree basic blocks |
| **Observable Semantics** | Reference behavior | **100% parity** (verified by differential tests) |

---

## Features

- **Expressive Fluent API**: `get`, `post`, `put`, `patch`, `delete`, `options`, `head`, `match`, `any`, `prefix`, `name`, `middleware`, `withoutMiddleware`, `where`, `whereNumber`, `whereAlpha`, `whereUuid`, `whereIn`, `defaults`, `fallback`, `route()`, `has()`.
- **Formal Grammar & Actionable Diagnostics**: EBNF-specified route DSL (`grammar/route.ebnf`), byte-span lexer/parser, and semantic analyzer with stable error codes, source locations, and hints.
- **First-Class Closure Compilation**: Extracts file-backed static closures & arrow functions via `nikic/php-parser` v5 (supporting stateless closures and scalar/array captures) with clear build-time diagnostics for unsupported captures (`$this`, `&byRef`, objects, `static` locals).
- **Standards Compliant**: Built around **PSR-7** (`ServerRequestInterface`, `ResponseInterface`), **PSR-11** (`ContainerInterface`), **PSR-15** (`MiddlewareInterface`, `RequestHandlerInterface`), and **PSR-17** (`ResponseFactoryInterface`, `StreamFactoryInterface`).
- **Strict Security & Normalization**: Segment-by-segment URI decoding (rejecting malformed escapes, encoded slashes/backslashes, NUL/control bytes, dot segments, and invalid UTF-8 with `400`), bounded regex inputs, and world-writable artifact rejection.
- **Atomic Artifact Publication**: Builds into a locked staging directory, runs `php -l` and load smoke tests, then atomically switches `releases/<buildId>` via `current` symlink for instant rollback.

---

## Requirements & Installation

- **PHP 8.4+** (OPcache recommended for production)
- **Composer 2**

```bash
composer require routir/routir
```

Or clone the repository for local development:

```bash
git clone <repository-url> routir
cd routir
composer install
```

---

## Quick Start

### 1. Define Routes (`routes.php`)

Return a typed closure `static function (Router $router): void` so your IDE provides full autocomplete and static type checking with zero undefined-variable warnings:

```php
<?php

declare(strict_types=1);

use Routir\API\Router;

return static function (Router $router): void {
    $router->get('/', static fn (): string => 'OK')->name('home');
    $router->get('/users/{id:int}', static fn (int $id): string => "User #{$id}")->name('users.show');

    $router->prefix('/api')->name('api.')->middleware(['json', 'auth'])->group(static function (Router $r): void {
        $r->get('/posts/{slug:slug}', static fn (string $slug): string => "Post: {$slug}")
            ->name('posts.show');
        $r->get('/health', static fn (): string => 'healthy')
            ->withoutMiddleware('auth')
            ->name('health');
    });
};
```

### 2. Run in Interpreted Mode (Development)

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

### 3. Build & Run in Compiled Mode (Production)

Compile your routes ahead of time (CI / deploy step):

```bash
php bin/routir build routes.php --output=var/routir -O2
```

Load only the compiled artifacts at runtime:

```php
use Routir\API\RouterFactory;
use Routir\Contracts\RouterMode;

$router = RouterFactory::create(
    mode: RouterMode::Compiled,
    artifact: __DIR__ . '/var/routir',
    container: $container,
);

$response = $router->dispatch($psr7Request);
```

> **OPcache Note**: After an atomic `current` symlink switch during deployment, reload PHP-FPM or call `opcache_reset()` if `opcache.revalidate_freq > 0` or `realpath_cache` is enabled.

---

## Configuration (`CompileConfig`)

Configure single files, multiple files, or entire route directories along with the output artifact directory using `CompileConfig`:

```php
use Routir\API\RouterFactory;
use Routir\Compiler\CompileConfig;
use Routir\Compiler\Compiler;
use Routir\Contracts\RouterMode;

$config = new CompileConfig(
    inputs: [__DIR__ . '/routes/web.php', __DIR__ . '/routes/api'],
    outputDir: __DIR__ . '/var/routir',
    mode: RouterMode::Auto, // Uses Compiled if artifacts exist, else falls back to Interpreted
    optimizationLevel: 2,
);

// Build artifacts
new Compiler()->compile($config);

// Boot router from config
$router = RouterFactory::fromConfig(
    config: $config,
    container: $container,
);
```

Or define a `routir.php` config file and run CLI commands without repeating flags:

```bash
php bin/routir build --config=routir.php
```

---

## CLI Reference

```bash
php bin/routir build [<routes>] [--input=PATH] --output=DIR [--config=FILE] [-O0|-O1|-O2]
php bin/routir check [<routes>] [--input=PATH] [--config=FILE]
php bin/routir list [<routes>] [--input=PATH] [--config=FILE]
php bin/routir dump:tokens <pattern>
php bin/routir dump:ast <pattern>
php bin/routir dump:ir [<routes>] [--input=PATH] [--stage=hir|mir|lir] [-O0|-O1|-O2]
php bin/routir dump:code [<routes>] [--input=PATH] [-O0|-O1|-O2]
php bin/routir clear [--output=DIR] [--config=FILE]
```

---

## Compiler Architecture

```text
Registration DSL ─▶ RouteLoader ─▶ Lexer ─▶ Parser ─▶ AST
  ─▶ SemanticAnalyzer (group flattening, constraints, middleware, handler plans)
  ─▶ HIR (normalized routes)
  ─▶ MIR (method partitions, ordered candidates, segment trie)
  ─▶ LIR (basic blocks + typed instructions)
  ─▶ Optimization Passes (-O0 baseline │ -O1 StaticDispatch │ -O2 TrieFactoring & BlockCleanup)
  ─▶ IRVerifier ─▶ PHP Backend (matcher.php, handlers.php, pipelines.php, url-generator.php, manifest.json)
```

---

## Examples

Both examples share [examples/routes.php](file:///home/long/Dev/router/examples/routes.php) and [examples/bootstrap.php](file:///home/long/Dev/router/examples/bootstrap.php), demonstrating all HTTP methods (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `HEAD`, `match`, `any`), handler types (arrow functions, scalar-capturing closures, DI controllers, invokable classes), PSR-15 middleware, and a custom JSON `ResponseAdapterInterface`:

```bash
# CLI runs (or: make examples)
php examples/01-interpreted/run.php
php examples/02-compiled/run.php   # emits artifacts to tmp/example-compiled/

# PHP built-in HTTP server (or: make serve-interpreted / make serve-compiled)
php -S 127.0.0.1:8000 examples/01-interpreted/server.php
php -S 127.0.0.1:8000 examples/02-compiled/server.php
```

---

## Benchmarks

Run the cross-framework benchmark (`make bench`, runs both OPcache ON & OFF sequentially per adapter):

```bash
make bench
# Or directly:
php benchmarks/compare/bench.php --routes=100,500
```

Measured on **PHP 8.4.22** (2,000 iterations after 100 warmup iterations, 500 routes, worst-case dynamic, sorted by `Dyn ops/s` descending):

### OPcache ON (`opcache.enable_cli=1`, JIT `tracing`)

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| **Routir (Compiled `-O2` match)** | 0.53 | **1.47** | 1.11 | **651,790** |
| **Routir (Interpreted match)** | 0.34 | 1.68 | **0.87** | **567,276** |
| Symfony Routing (`Compiled`) | 0.30 | 2.07 | 1.81 | 430,199 |
| **Routir (Compiled `-O2` PSR-7)** | 2.13 | 3.15 | 1.67 | 305,158 |
| FastRoute (`cachedDispatcher`) | **0.15** | 6.26 | 4.18 | 153,381 |
| FastRoute (`simpleDispatcher`) | 0.16 | 6.58 | 4.20 | 138,669 |
| League Route (PSR-7 dispatch) | 3.74 | 11.43 | 6.53 | 82,832 |
| Laravel Routing (`Compiled`) | 10.36 | 13.44 | — | 61,000+ |

### OPcache OFF (`opcache.enable_cli=0`)

| Router / Mode | Static (µs) | Dyn p50 (µs) | Miss p50 (µs) | Dyn ops/s |
| --- | ---: | ---: | ---: | ---: |
| **Routir (Compiled `-O2` match)** | 0.45 | **1.54** | **1.19** | **596,432** |
| Symfony Routing (`Compiled`) | 0.64 | 2.63 | 2.21 | 347,248 |
| **Routir (Interpreted match)** | 0.47 | 3.13 | 1.52 | **231,623** |
| **Routir (Compiled `-O2` PSR-7)** | 2.19 | 3.76 | 2.23 | 214,053 |
| FastRoute (`cachedDispatcher`) | **0.26** | 7.60 | 5.31 | 125,873 |
| FastRoute (`simpleDispatcher`) | **0.26** | 7.73 | 5.24 | 120,799 |
| Laravel Routing (`Compiled`) | 11.41 | 14.84 | 18,134.55 | 58,881 |

*(Actual numbers depend on hardware, PHP version, and OPcache/JIT configuration. See [docs/performance-notes.md](file:///home/long/Dev/router/docs/performance-notes.md) for optimization details.)*

---

## Testing & Quality Gates

```bash
# Run all quality gates via Makefile (auto-detects local PHP 8.4+ or Docker dev container)
make qa

# Or run individual commands:
# Full Pest suite (Unit, Integration, Differential O0/O1/O2, Golden, Fuzz)
vendor/bin/pest            # make test

# PHPStan static analysis (Level 9)
vendor/bin/phpstan analyse --no-progress   # make phpstan
```

Run `make help` to see all available DX targets (`test-unit`, `test-diff`, `coverage`, `lint`, `route-check`, `clean`, etc.).

---

## Docker Dev Environment

Spin up a ready-to-use **PHP 8.4** development container (with OPcache, JIT, PCov, and Composer 2):

```bash
make up          # docker compose up -d
make install     # composer install
make qa          # pest + phpstan + examples + route-check
make bench       # cross-framework benchmarks (OPcache ON & OFF)
make shell       # interactive bash inside dev container
```

All `Makefile` targets auto-detect whether PHP 8.4+ is available locally (`DOCKER=0`) or fall back to `docker compose exec -T dev` (`DOCKER=1`). You can override this anytime with `make <target> DOCKER=0` or `DOCKER=1`.

---

## Documentation

- [Engineering Specification](file:///home/long/Dev/router/Routir-SPEC.md)
- [Usage Guide](file:///home/long/Dev/router/docs/usage-guide.md)
- [Compiler Guide](file:///home/long/Dev/router/docs/compiler-guide.md)
- [Architecture Decision Records (ADRs)](file:///home/long/Dev/router/docs/adr.md)
- [Performance Notes & Optimization Log](file:///home/long/Dev/router/docs/performance-notes.md)
- [Coding Agent Guidelines](file:///home/long/Dev/router/AGENTS.md)

---

## Contributing

Contributions are warmly welcomed! Whether you are fixing a bug, adding an optimization pass, improving diagnostics, or expanding documentation and benchmarks, your help is appreciated.

### How to Contribute

1. **Fork** the repository and create a feature branch from `main` (e.g., `feat/radix-compression` or `fix/url-encoding`).
2. **Follow the project conventions** in [AGENTS.md](file:///home/long/Dev/router/AGENTS.md):
   - PHP 8.4+ with `declare(strict_types=1);` and PSR-12 formatting.
   - Use `final` and `readonly` wherever applicable, named arguments for 3+ parameter calls (or boolean/nullable flags), and explicit imports (avoid inline FQN/`\`).
   - Provide complete PHPDoc blocks (`description`, `@param`, `@return`, `@throws`).
   - Keep `src/Runtime/`, `src/Middleware/`, `src/Contracts/`, and `src/Exception/` free of compiler/AST dependencies.
   - Maintain 100% observable parity between **Interpreted** and **Compiled** (`-O0`, `-O1`, `-O2`) modes.
3. **Add tests** using **Pest** (`tests/`) covering both happy paths and edge cases.
4. **Verify all quality gates** pass before opening a Pull Request (`make qa`, or run individually):
   ```bash
   vendor/bin/pest
   vendor/bin/phpstan analyse --no-progress
   php examples/01-interpreted/run.php
   php examples/02-compiled/run.php
   php bin/routir check examples/routes.php
   ```
5. **Open a Pull Request** with a clear description of the motivation, design tradeoffs, and test results.

---

## License

Routir is open-source software licensed under the [MIT License](https://opensource.org/licenses/MIT).
