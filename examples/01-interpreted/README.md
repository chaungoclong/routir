# Example 01: Interpreted Mode

Chạy bộ route dùng chung [examples/routes.php](file:///home/long/Dev/router/examples/routes.php) ở chế độ **Interpreted** (kèm PSR-15 middleware, PSR-11 DI, custom JSON `ResponseAdapterInterface`, đầy đủ các HTTP methods và các kiểu handler).

## Chạy thử CLI
```bash
php examples/01-interpreted/run.php
```

## Chạy qua PHP Built-in Server
```bash
php -S 127.0.0.1:8000 examples/01-interpreted/server.php
curl -i http://127.0.0.1:8000/api/articles/10
curl -i -X POST http://127.0.0.1:8000/api/articles
curl -i -X PUT http://127.0.0.1:8000/api/articles/10
curl -i -X PATCH http://127.0.0.1:8000/api/articles/10
curl -i -X DELETE http://127.0.0.1:8000/api/articles/10
```
