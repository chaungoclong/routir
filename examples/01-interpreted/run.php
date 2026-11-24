<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../bootstrap.php';

use Examples\Shared\JsonResponseAdapter;
use Routir\API\RouterFactory;
use Routir\Contracts\RouterMode;
use Routir\Runtime\MinimalServerRequest;

use function Examples\Shared\createContainer;

$router = RouterFactory::create(
    mode: RouterMode::Interpreted,
    routes: __DIR__ . '/../routes.php',
    container: createContainer(),
    responseAdapter: new JsonResponseAdapter(),
);

$requests = [
    ['GET', '/'],
    ['GET', '/users/42'],
    ['HEAD', '/users/42'],
    ['GET', '/api/articles/10'],
    ['POST', '/api/articles'],
    ['PUT', '/api/articles/10'],
    ['PATCH', '/api/articles/10'],
    ['DELETE', '/api/articles/10'],
    ['OPTIONS', '/api/articles'],
    ['GET', '/api/health'],
    ['GET', '/api/secret'],
    ['PURGE', '/api/cache/home-page'],
    ['PUT', '/api/echo'],
    ['GET', '/api/files/docs/guide.md'],
    ['GET', '/unknown'],
];

echo "=== Interpreted Mode ===\n";
foreach ($requests as [$method, $target]) {
    $res = $router->dispatch(new MinimalServerRequest($method, $target));
    echo sprintf(
        "%-7s %-28s -> %d | X-Role=%-5s | %s\n",
        $method,
        $target,
        $res->getStatusCode(),
        $res->getHeaderLine('X-Role') ?: '-',
        (string) $res->getBody(),
    );
}

echo "\nReverse routing:\n";
echo '  api.articles.show => ' . $router->route('api.articles.show', ['id' => 10]) . "\n";
echo '  api.files.show    => ' . $router->route('api.files.show', ['path' => 'docs/guide.md']) . "\n";
