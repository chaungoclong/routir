# Routir Compiler Guide

## End-to-End Pipeline
```
Registration DSL -> Collector -> Lexer -> Parser -> AST
 -> Semantic analysis / Group flattening / Handler analysis
 -> HIR (normalized routes, handler and middleware plans)
 -> MIR (method partitions, ordered candidates, segment trie)
 -> LIR (basic blocks + typed instructions)
 -> Optimization passes (-O0 / -O1 / -O2) -> IRVerifier
 -> PHP Backend -> Verified Artifacts
```

## 1. Lexical Analysis vs. Syntactic Analysis
Routir's pattern frontend separates **lexical analysis** (`Routir\Frontend\Lexer\Lexer`) from **syntactic analysis** (`Routir\Frontend\Parser\Parser`).

- **Lexer**: Scans raw route strings byte-by-byte into `Slash`, `LeftBrace`, `RightBrace`, `Colon`, `Question`, `Star`, `Text`, and `EOF` tokens with byte offsets and lengths. `Text` is a maximal run of allowed literal characters (`a-z`, `A-Z`, `0-9`, `-`, `_`, `.`, `~`).
- **Parser**: Recursive descent parser implementing `grammar/route.ebnf`. Validates identifier context inside `{...}` and after `:`, enforces leading `/`, rejects empty segments (`//`, trailing `/`), rejects optional wildcards, and requires `EOF`.

## 2. Walkthrough: `/users/{id:int}`
1. **Tokens**: `Slash("/")`, `Text("users")`, `Slash("/")`, `LeftBrace("{")`, `Text("id")`, `Colon(":")`, `Text("int")`, `RightBrace("}")`, `EOF("")`
2. **AST**: `RouteNode("/users/{id:int}", [LiteralNode("users"), ParameterNode("id", "int", optional=false, wildcard=false)])`
3. **HIR**: `HIRRoute(id=0, methods=["GET"], pattern="/users/{id:int}", ...)`
4. **MIR**: `MIRCandidate(routeId=0, segments=[literal("users"), param("id", builtin:int)])`
5. **LIR (-O0)**:
   ```
   cand_0:
     ResetCaptures
     ReadSegment idx=0 -> $seg0 ? cand_0_s0_chk : miss
   cand_0_s0_chk:
     GuardLiteral $seg0 == "users" ? cand_0_s1 : miss
   cand_0_s1:
     ReadSegment idx=1 -> $seg1 ? cand_0_s1_chk : miss
   cand_0_s1_chk:
     GuardConstraint $seg1 : builtin:int ? cand_0_s1_cap : miss
   cand_0_s1_cap:
     Capture id <- $seg1
     BranchEOF count==2 ? cand_0_match : miss
   cand_0_match:
     ReturnMatch route=0 defaults=[]
   ```
6. **Optimization (-O1 / -O2)**:
   - `-O1` (`StaticDispatchPass`) moves exact static routes into `STATIC_TABLES` for O(1) lookup.
   - `-O2` (`TrieFactoringPass`) factors shared leading literal prefixes across contiguous dynamic candidates so `ReadSegment` + `GuardLiteral` are executed once per shared prefix.
7. **PHP Backend**: Emits `matcher.php`, `handlers.php`, `pipelines.php`, `url-generator.php`, `metadata.php`, `manifest.json`, and `source-map.json` into a staging directory, runs `php -l`, runs a load smoke test, and atomically publishes `releases/<buildId>` + `current`.
