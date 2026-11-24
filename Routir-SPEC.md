# Routir — Engineering Specification

Version: 0.1 | Status: proposed implementation contract | Target: PHP 8.4+

## 1. Mission and normative language
Build a framework-agnostic, Laravel-inspired HTTP router with interpreted and ahead-of-time (AOT) compiled execution modes. Move routing computation to build time while preserving PHP-FPM request isolation. Compiler complexity is acceptable; runtime complexity must be justified. MUST denotes a release requirement; SHOULD denotes a preferred design; MAY denotes optional work. This is a design specification, not a claim of implemented features or measured performance.

### Goals
- Identical observable routing, handler, middleware and URL-generation semantics in both modes for supported definitions.
- No runtime route registration, pattern parsing, trie construction, handler reflection or closure deserialization in compiled mode.
- Generate ordinary, readable PHP source suitable for OPcache; no eval, native extension or persistent worker required.
- Support closures as first-class handlers with an explicitly defined compilable subset and actionable diagnostics for unsupported cases.
- Teach compiler architecture through formal grammar, lexer, parser, AST, semantic analysis, IR, optimization and backend.
- Optimize measured latency, allocation and throughput rather than claiming universally optimal control flow.

Non-goals: full Laravel compatibility, a general PHP compiler, arbitrary closure serialization, full DI container, HTTP server, guaranteed fastest router on every workload.

## 2. Public API and modes
```php
$router->get('/', static fn () => 'OK')->name('home');
$router->get('/users/{id:int}', [UserController::class, 'show'])
    ->middleware('auth')->name('users.show');
$router->prefix('/api')->name('api.')->middleware(['json', 'auth'])
    ->group(function (Router $r) {
        $r->get('/posts/{slug:slug}', static function (string $slug, PostService $service) {
            return $service->findBySlug($slug);
        })->name('posts.show');
    });
```
Support get, post, put, patch, delete, options, head, match, any; prefix, name, middleware, group, withoutMiddleware; where, whereNumber, whereAlpha, whereUuid, whereIn, defaults; fallback; route(name, params), has(name), getRoutes, getByName. Resource/apiResource and domain routing are later milestones, not MVP blockers. Route names are globally unique. Group builder and route builder MUST be distinct; group state MUST unwind in finally on exceptions. Freeze registration before matching/building.

```php
$router = RouterFactory::create(mode: RouterMode::Interpreted, routes: $file, container: $container);
$router = RouterFactory::create(mode: RouterMode::Compiled, artifact: $dir, container: $container);
$response = $router->dispatch($request);
$url = $router->route('users.show', ['id' => 42]);
```
Interpreted mode executes registration, shared frontend and reference matcher; compiled mode loads artifacts only. Auto mode MAY fall back explicitly in development; Compiled mode MUST fail fast for absent/incompatible artifacts. No silent fallback in production. Public matcher returns a structured MatchResult (matched route ID, raw string parameters, status, allowed methods); dispatcher invokes handlers separately. PSR-7/15/11/17 are the integration boundaries. Normalize handler results via configurable response adapter; default accepts ResponseInterface and string (text response), rejects other values unless adapter configured (examples returning domain objects require adapter).

## 3. Formal language
Route DSL is embedded in PHP registration; do not implement a PHP parser. `grammar/route.ebnf`:
```ebnf
route       = "/" [ segment { "/" segment } ] ;
segment     = literal | parameter ;
parameter   = "{" identifier [ ":" constraint ] [ "?" ] "}" ;
constraint  = identifier | "*" ;
identifier  = (letter | "_") { letter | digit | "_" } ;
literal     = literal_char { literal_char } ;
literal_char = letter | digit | "-" | "_" | "." | "~" ;
letter      = "a" … "z" | "A" … "Z" ;
digit       = "0" … "9" ;
```
MVP patterns: `/`, `/users`, `/users/{id}`, `/users/{id:int}`, `/users/{id?}`, `/files/{path:*}`. Optional segments only in trailing contiguous suffix; wildcard only final, nonempty, not optional. Reject duplicate parameter names, unknown constraints, empty segments, malformed patterns, conflicting inline/fluent constraints. Custom regex supplied via where(), not embedded grammar. Later extensions require grammar and compatibility tests.

Lexer emits Slash, LeftBrace, RightBrace, Colon, Question, Star, Text, EOF with byte offset and length. Text is a maximal run of allowed literal characters; parser validates identifier context (avoids ambiguous Identifier/Literal tokens). Recursive descent parser produces immutable RouteNode, LiteralNode, ParameterNode with source spans. Require EOF; no partial parse acceptance. Diagnostics include stable code, file, line if available, byte span, offending text and hint. Semantic analyzer validates AST, builds name/constraint symbols, normalizes group attributes, validates handlers and detects conflicts.

## 4. Normative matching semantics
- HTTP method is case-sensitive; registration accepts uppercase valid method tokens. any expands to documented standard methods (GET HEAD POST PUT PATCH DELETE OPTIONS); extension methods via match.
- Match URI path only, excluding query/fragment. Require leading slash. Split raw path at literal slash before percent-decoding each segment exactly once; reject malformed escapes, decoded slash/backslash, NUL/control bytes, dot segments and invalid UTF-8. No automatic slash collapsing or case folding. Trailing slash is distinct and unmatched in MVP except root; invalid request target yields 400. Apply same normalization in both modes. Wildcard captures decoded segments joined with slash.
- Constraints: int = one or more ASCII digits (no sign, no numeric coercion); alpha = ASCII letters; alnum = ASCII letters/digits; slug = nonempty ASCII alnum groups separated by single hyphens; uuid = canonical 8-4-4-4-12 hex shape, case insensitive; unconstrained = nonempty valid segment. whereIn uses strict string equality. Custom regex anchored to entire segment using PCRE, validated at build; enforce bounded input and PCRE limits; distinguish regex engine error from no match.
- Precedence: exact static path first, then lexicographic per-segment specificity literal > constrained parameter > unconstrained > wildcard; exact terminal beats omitted optional suffix; ties use registration order. Reject identical normalized method/path/constraint signatures and duplicate names. Warn for detectable overlaps; do not claim arbitrary regex intersection decidability. Preserve fallback to lower-priority candidates if a more-specific branch fails deeper in path. Expand optional suffixes or lower equivalently; preserve precedence.
- Explicit HEAD route wins; otherwise use GET match and suppress body at HTTP boundary (preserve appropriate headers). Explicit OPTIONS wins; otherwise synthesize 204 with Allow for known path. If path matches other methods return 405 with deterministic Allow including implicit HEAD/OPTIONS. Unknown path returns 404; fallback applies only to 404, not 400/405. Global middleware policy must be documented; MVP route middleware executes only after match.
- Defaults apply only to absent optional parameters; captures are strings. No implicit integer cast in matcher. Handler binding may explicitly convert with range checks; invalid conversion is a documented dispatch error. No mutable shared request state.

## 5. Groups, middleware and names
Group flattening: concatenate path prefixes with exactly one boundary slash; concatenate name prefixes verbatim; inherit middleware outer-to-inner then route; inherit constraints/defaults with inner override; explicit exclusions remove matching alias/class according to documented canonical identity. Reject invalid group cycles and duplicate names. Middleware aliases map to class/service IDs; groups recursively expand with cycle detection; `alias:a,b` parameters are strings, split on commas (no escaping in MVP). Preserve duplicates unless explicitly excluded; never deduplicate executions. Optional priority configuration uses stable ordering and rejects contradictory rules. PSR-15 middleware uses process(request,next); parameterized middleware resolved via explicit factory/adapter, not extra PSR-15 method arguments. Preserve request replacement, short-circuit, response unwinding, exceptions, and repeated next invocation; resolve services lazily at the same point in both modes. Compiled pipeline may share identical stage topology, never share mutable request context. Do not claim zero allocations.

Reverse routing: name lookup, required parameter validation, optional trailing omission, defaults, constraint validation, rawurlencode per segment, wildcard encoding per segment. Reject slash in non-wildcard values, invalid values and optional gaps; extra parameters become query string using RFC3986 encoding. Generate relative paths by default; absolute URLs/domain routing later. Generated URL must match intended route where precedence permits; document shadowed paths. Both modes throw identical typed exceptions for unknown names/missing/invalid params.

## 6. Handler and closure compilation
Use nikic/php-parser v5 at build time for PHP AST extraction and name resolution. Native closures in interpreted mode. Supported controller handlers: invokable class/service ID, [class, method], explicitly registered factory. Compile invocation metadata from reflection at build time; runtime PSR-11 lookup remains request scoped. Parameter binding priority: explicit binding > named route capture > explicitly recognized request/context type > class/interface service > default > error. Reject ambiguous unions/intersections, unsupported by-reference/variadic signatures unless explicitly supported. Same binding rules in both modes. No reflection in compiled dispatch.

Closure policy:
- MVP compile file-backed static anonymous and arrow functions with no captures; preserve actual closure syntax in generated factory or handler file, rather than blindly converting to named functions. Resolve namespace/imports, function/constant fallback, magic constants, strict_types and lexical scope correctly or reject unsupported constructs. Reject ambiguous same-line source candidates unless exact AST identity can be established. Do not use regex to extract PHP source.
- Phase 2 supports by-value scalar/null and recursively scalar array captures via safe literal emission and factory initialization. Capture values at registration/build; parity requires deterministic deployment-time values. Reject environment-dependent or request-dependent captures unless explicit runtime factory. Never silently embed secrets; document artifact exposure and allow explicit capture opt-in.
- Object/resource captures, by-reference captures, bound $this, class-private scope, dynamic/eval source, unsupported magic constants, static locals or mutable capture semantics: reject in compiled mode with source diagnostic unless a proven equivalent strategy exists. Interpreted mode may support native closures beyond compilable subset; check/build reports incompatibility. Explicit runtime factory service is escape hatch, not silent interpreted fallback.
- Preserve closure lifetime: one instance per router/request where required; lazy creation only if observationally equivalent. No arbitrary closure serialization, unserialize, eval or source execution from untrusted input. Build executes trusted route registration code in controlled CI. Generated PHP must quote all literals safely (var_export for validated scalars/arrays or AST printer); never interpolate untrusted PHP fragments.
- Named function conversion, constant propagation and inlining are optional optimizations only with proof/tests preserving semantics (including namespace, mutation, exceptions and stack-observable behavior where contract requires).

## 7. Compiler architecture
```
Registration DSL -> Collector -> Lexer -> Parser -> AST
 -> Semantic analysis / Group flattening / Handler analysis
 -> HIR (normalized routes, handler and middleware plans)
 -> MIR (method partitions, segment trie, reverse URL plans)
 -> Optimization passes -> LIR (decision graph / basic blocks)
 -> PHP backend -> verified artifacts
```
Shared frontend feeds reference interpreter independently of optimized matcher. HIR RouteIR: stable ID, methods, path segments, constraints, handler plan, middleware plan, name, defaults, source span, registration order. MIR trie edges carry literal/constraint/capture descriptors; terminal stores route ID; branch priority explicit. LIR instructions: GuardMethod, MatchStatic, GuardPrefix, ReadSegment, GuardConstraint, BranchEOF, Capture, ReturnMatch, ReturnMiss; explicit success/failure edges, capture rollback or SSA-like branch-local values. Validate graph targets, termination, initialized captures, terminal references and precedence after lowering and each pass in debug/test builds. IR is inspectable and deterministic; no runtime IR interpreter in compiled mode.

Optimization pass interface: run(IR, CompilationContext): IR. Pass manager records ordering, timings and diagnostics. O0 baseline codegen; O1 exact dispatch and basic factoring; O2 default adds shared prefixes, radix compression, constraint specialization, block merging, pipeline deduplication; O3/Os/PGO experimental after benchmarks. Reorder only semantically independent branches. Never prune potentially matching fallback branches. Avoid repeated substr/explode, regex and allocations where measurements justify; use strict equality/hash dispatch for exact paths, specialized segment guards, shared prefixes and PCRE fallback. Cost model considers route count, fanout, code size, OPcache and workload. Avoid enormous generated methods; split by method/prefix when justified. Generated code must be deterministic and readable. Do not promise asymptotic or wall-clock superiority without benchmark evidence.

## 8. Backend and artifacts
Emit matcher.php, handlers.php, pipelines.php, url-generator.php, metadata.php, manifest.php (or JSON), source-map.json. Matcher returns route ID/captures; invoker dispatches via generated code; pipeline and reverse generator use resolved plans. Runtime must not load compiler or source definitions. Manifest includes schema/compiler/runtime version, PHP target, normalized options, input content hashes including transitive declared dependencies, artifact hashes and build ID. No timestamps in deterministic payloads. Build in staging directory, lint every PHP artifact (`php -l`), run smoke tests, then publish immutable versioned directory and atomically switch manifest/pointer on same filesystem. Lock concurrent builds. Retain previous version for rollback; document OPcache invalidation/restart for deployments. Runtime validates compatibility once per router load; expensive hash verification at deployment or explicit verification, not every match. Never load artifacts writable by untrusted users.

## 9. Repository layout
```
grammar/route.ebnf
src/API/{Router,RouteBuilder,GroupBuilder,RouteCollection}.php
src/Contracts/
src/Frontend/{Lexer,Parser,AST,Collector,Handler}/
src/Semantic/
src/IR/{HIR,MIR,LIR}/
src/Analysis/
src/Transform/{Lowering,Optimization}/
src/Compiler/{Compiler,CompilerOptions,PassManager}.php
src/Backend/PHP/
src/Interpreter/
src/Runtime/
src/Middleware/
src/DependencyInjection/
src/Diagnostics/
src/Exception/
tests/{Unit,Integration,Golden,Differential,Fuzz}/
benchmarks/
examples/
docs/
bin/routir
composer.json
```
PSR-4, strict_types, typed APIs, immutable IR where practical. Dependencies: PSR interfaces at runtime; PHP-Parser at build time; Pest, PHPStan, PHPBench in dev. Start monorepo/single package with clean dependency direction; split compiler/runtime packages later. No runtime dependency on compiler namespace.

CLI: build routes.php --output=DIR -O2; check routes.php; list routes.php; dump:tokens PATTERN; dump:ast PATTERN; dump:ir routes.php --stage=hir|mir|lir; dump:code routes.php; clear --output=DIR (only managed artifacts). Nonzero exit on errors, diagnostics stderr, machine-readable output optional. README contains runnable examples and build/deploy instructions.

## 10. Testing and performance gates
- Lexer/parser unit tests: every production, spans, invalid syntax, EOF, root, optional/wildcard constraints.
- Semantic tests: duplicate names/params/routes, invalid regex, group inheritance, middleware cycles, ambiguous closures.
- Differential tests: independently implemented reference matcher vs generated matcher at each optimization level, compare status, route ID, captures, Allow, URLs, handler response and middleware trace. Include overlapping prefixes where preferred branch fails deeper, optional/static conflicts, encoded delimiters, Unicode, invalid percent, 404/405/HEAD/OPTIONS.
- Closure fixtures: namespace imports, arrows, scalar captures, unsupported captures, same-line ambiguity, strict types, DI defaults, rejection diagnostics. Test generated files in fresh PHP processes without source registration or compiler autoload.
- Middleware tests: order, exclusions, aliases/parameters, short-circuit, modified request, exception, repeated next, lazy service resolution. Named route tests: roundtrip, missing/extra params, defaults, encoding.
- Golden tests: stable AST/IR/source output, deterministic builds; lint generated PHP. Fuzz random valid/invalid patterns and paths with fixed seeds and minimized reproducers. Security tests for code injection, malformed URLs, regex resource exhaustion and artifact tampering.
- Benchmark reference interpreter, FastRoute, Symfony Routing, runtime trie and compiled levels using equivalent semantics. Route counts 10/100/1k/10k; static/dynamic/mixed, hit/miss, hot/uniform, deep/shared prefixes. Measure registration/build time separately, cold/warm match latency, p50/p95/p99, throughput, peak memory, generated size, OPcache usage; end-to-end PHP-FPM tests with OPcache on/off, JIT configuration recorded. Record PHP version, hardware, concurrency, warmup, iterations. Do not invent results. Establish baseline before numeric performance gates; reject regressions beyond agreed threshold after measurement.

## 11. Implementation milestones and agent instructions
1. Scaffold Composer, grammar, lexer/parser/AST/diagnostics, tests and token/AST dump commands. Explain lexical vs syntactic analysis in docs.
2. Implement fluent API, group flattening, semantic analyzer, constraint registry and reference matcher. Lock matching semantics with fixtures.
3. Implement named URL generation, handler binding, PSR-15 middleware and interpreted dispatch; integration tests.
4. Implement HIR/MIR/LIR, trie lowering, IR verifier and O0 PHP backend. Differential tests before optimization.
5. Implement closure AST extraction and safe stateless compilation, then scalar captures; diagnostics and fresh-process tests.
6. Implement compiled invocation, middleware and reverse routing; verify full observable parity.
7. Implement O1/O2 passes individually, with before/after IR examples, tests and benchmarks. Choose strategies based on measured cost.
8. Artifact publication, CLI, security hardening, fuzzing, PHPStan, CI and production deployment documentation.
9. Optional extensions: domain routing, resource/apiResource, advanced closure factories, O3/Os/PGO, package split.

Agent MUST work incrementally, keep tests green, provide runnable code rather than placeholder modules, and report commands/results honestly. Do not implement speculative features before baseline correctness. For each milestone provide changed files, design rationale, tests, known limitations and next steps. Maintain docs/compiler-guide.md with source -> tokens -> AST -> HIR -> MIR -> LIR -> generated PHP walkthrough. Maintain ADRs for precedence, closure policy, normalization, middleware semantics and optimization tradeoffs. If a requirement is ambiguous, choose the simplest safe behavior, document it and add tests; do not silently change this contract. Production-ready status requires completed mandatory milestones and passing correctness/security gates, not merely successful code generation.

## 12. Learning references
- Crafting Interpreters: https://craftinginterpreters.com/
- LLVM Kaleidoscope: https://llvm.org/docs/tutorial/
- PHP-Parser: https://github.com/nikic/PHP-Parser
- PHP-FIG standards: https://www.php-fig.org/psr/
- PHP OPcache: https://www.php.net/manual/en/book.opcache.php

Core principle: compile once, execute many, reset every request.
