<?php

declare(strict_types=1);

/**
 * Helper to start PHP built-in server on a free loopback port and send raw HTTP requests.
 *
 * @return array{proc: resource, port: int}
 */
function startBuiltInServer(string $routerScript): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException("Could not allocate free port: $errstr ($errno)");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('Failed to parse allocated port.');
    }
    $port = (int) substr($name, strrpos($name, ':') + 1);

    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, $routerScript];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start PHP built-in server.');
    }

    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        set_error_handler(static fn (): bool => true);
        try {
            $conn = stream_socket_client('tcp://127.0.0.1:' . $port, $e1, $e2, 0.1);
        } finally {
            restore_error_handler();
        }
        if (is_resource($conn)) {
            fclose($conn);

            return ['proc' => $proc, 'port' => $port];
        }
        usleep(50_000);
    }

    proc_terminate($proc);
    proc_close($proc);
    throw new RuntimeException("Built-in server did not become ready on port $port.");
}

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function sendHttpRequest(int $port, string $method, string $target): array
{
    $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
    if (!is_resource($fp)) {
        throw new RuntimeException("Failed to connect to 127.0.0.1:$port: $errstr ($errno)");
    }

    $rawReq = sprintf("%s %s HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nConnection: close\r\n\r\n", $method, $target, $port);
    fwrite($fp, $rawReq);
    $rawResp = stream_get_contents($fp);
    fclose($fp);

    if (!is_string($rawResp) || !str_contains($rawResp, "\r\n\r\n")) {
        throw new RuntimeException('Invalid HTTP response received.');
    }

    [$rawHeaders, $body] = explode("\r\n\r\n", $rawResp, 2);
    $lines = explode("\r\n", $rawHeaders);
    $statusLine = array_shift($lines);
    preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $statusLine, $m);
    $status = isset($m[1]) ? (int) $m[1] : 0;

    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

test('interpreted and compiled examples have identical behavior over php built-in server', function (string $script): void {
    $srv = startBuiltInServer(dirname(__DIR__, 2) . '/' . $script);
    try {
        $rRoot = sendHttpRequest($srv['port'], 'GET', '/');
        expect($rRoot['status'])->toBe(200)
            ->and($rRoot['body'])->toBe('OK');

        $rGet = sendHttpRequest($srv['port'], 'GET', '/api/articles/10');
        expect($rGet['status'])->toBe(200)
            ->and($rGet['headers']['content-type'] ?? '')->toBe('application/json')
            ->and($rGet['headers']['x-role'] ?? '')->toBe('admin')
            ->and($rGet['body'])->toBe('{"action":"show","id":10}');

        $rPost = sendHttpRequest($srv['port'], 'POST', '/api/articles');
        expect($rPost['status'])->toBe(200)
            ->and($rPost['body'])->toBe('{"action":"created","via":"store","method":"POST","role":"admin"}');

        $rPut = sendHttpRequest($srv['port'], 'PUT', '/api/articles/10');
        expect($rPut['status'])->toBe(200)
            ->and($rPut['body'])->toBe('{"action":"replaced","id":10}');

        $rPatch = sendHttpRequest($srv['port'], 'PATCH', '/api/articles/10');
        expect($rPatch['status'])->toBe(200)
            ->and($rPatch['body'])->toBe('{"action":"patched","id":10}');

        $rDelete = sendHttpRequest($srv['port'], 'DELETE', '/api/articles/10');
        expect($rDelete['status'])->toBe(200)
            ->and($rDelete['body'])->toBe('{"action":"deleted","id":10,"from":"store"}');

        $rOpts = sendHttpRequest($srv['port'], 'OPTIONS', '/api/articles');
        expect($rOpts['status'])->toBe(200)
            ->and($rOpts['body'])->toBe('OPTIONS /api/articles supported');

        $rHealth = sendHttpRequest($srv['port'], 'GET', '/api/health');
        expect($rHealth['status'])->toBe(200)
            ->and(isset($rHealth['headers']['x-role']))->toBeFalse()
            ->and($rHealth['body'])->toBe('healthy');

        $rSecret = sendHttpRequest($srv['port'], 'GET', '/api/secret');
        expect($rSecret['status'])->toBe(403)
            ->and($rSecret['body'])->toBe('Access denied');

        $rEcho = sendHttpRequest($srv['port'], 'PUT', '/api/echo');
        expect($rEcho['status'])->toBe(200)
            ->and($rEcho['body'])->toBe('{"echo":"PUT"}');

        $r404 = sendHttpRequest($srv['port'], 'GET', '/unknown');
        expect($r404['status'])->toBe(404)
            ->and($r404['headers']['content-type'] ?? '')->toBe('application/json')
            ->and($r404['body'])->toBe('{"error":"Not found","path":"\/unknown"}');
    } finally {
        proc_terminate($srv['proc']);
        proc_close($srv['proc']);
    }
/** @phpstan-ignore method.notFound */
})->with([
    '01-interpreted' => ['examples/01-interpreted/server.php'],
    '02-compiled' => ['examples/02-compiled/server.php'],
]);
