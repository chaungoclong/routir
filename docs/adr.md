# Architecture Decision Records (ADRs)

## ADR-001: Route Precedence & Backtracking
- **Decision**: Exact static paths have highest priority, followed by lexicographic per-segment specificity (`literal` > `constrained parameter` > `unconstrained parameter` > `wildcard`), with exact terminals beating omitted optional suffixes, and ties broken by registration order.
- **Tradeoff**: When a more-specific branch fails deeper in the path (e.g. `/{user:alpha}/settings` fails on segment 1 for `/john/posts`), the matcher MUST fall back to lower-priority candidates (`/{any}/posts`). Branch-local capture resets (`ResetCaptures`) prevent partial captures from leaking across failed branches.

## ADR-002: URI Path Normalization
- **Decision**: Split raw request target at literal `/` *before* percent-decoding each segment once. Reject missing leading `/`, malformed `%` escapes, decoded `/` or `\`, control/NUL bytes, `.` / `..` segments, and invalid UTF-8 with `400 InvalidRequestTarget`. Trailing slashes are distinct and unmatched in MVP (except `/`).
- **Rationale**: Prevents path traversal and double-decoding smuggling while preserving identical behavior in Interpreted and Compiled modes.

## ADR-003: Closure Compilation Policy
- **Decision**: Use `nikic/php-parser` v5 AST extraction + `NameResolver` for file-backed static closures/arrows with no captures or recursively scalar/null/scalar-array captures. Reject bound `$this`, by-ref captures, object/resource captures, static locals, and same-line ambiguous closures with actionable diagnostics.
- **Rationale**: Preserves PHP-FPM request isolation and avoids `eval()` or unsafe closure serialization.

## ADR-004: PSR-15 Middleware Semantics
- **Decision**: Route middleware executes only after a route matches (not on 400/404/405). Middleware aliases expand recursively with cycle detection; `withoutMiddleware` matches both exact entries and base alias/class names; duplicates are preserved unless excluded. `IndexedPipelineHandler` creates an immutable step per index so repeated `$handler->handle($request)` calls work cleanly.

## ADR-005: Optimization Tradeoffs (-O0 / -O1 / -O2)
- **-O0**: Direct candidate-chain lowering; simplest baseline for differential testing.
- **-O1 (`StaticDispatchPass`)**: Extracts exact static paths into O(1) `STATIC_TABLES[$method][$rawPath]` hash lookups, reducing dynamic fallback chains.
- **-O2 (`TrieFactoringPass` + `BlockCleanupPass`)**: Factors contiguous runs of dynamic routes sharing literal prefixes into shared `ReadSegment` + `GuardLiteral` blocks and prunes unreachable blocks, shrinking generated PHP size and redundant comparisons while preserving exact fallback order.
