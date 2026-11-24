# Example 02: AOT Compiled Mode

Biên dịch bộ route dùng chung [examples/routes.php](file:///home/long/Dev/router/examples/routes.php) với `CompileConfig` (`-O2`) ra thư mục `tmp/example-compiled/` ở root, sau đó chạy ở chế độ **Compiled** với hành vi giống hệt chế độ Interpreted.

## Chạy thử CLI & xem code sinh ra
```bash
php examples/02-compiled/run.php
ls -la tmp/example-compiled/current/
```

## Chạy qua PHP Built-in Server
```bash
php -S 127.0.0.1:8000 examples/02-compiled/server.php
curl -i http://127.0.0.1:8000/api/articles/10
curl -i -X POST http://127.0.0.1:8000/api/articles
curl -i -X PUT http://127.0.0.1:8000/api/articles/10
curl -i -X PATCH http://127.0.0.1:8000/api/articles/10
curl -i -X DELETE http://127.0.0.1:8000/api/articles/10
```
