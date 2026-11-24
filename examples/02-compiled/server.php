<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../bootstrap.php';

use Examples\Shared\JsonResponseAdapter;
use Routir\API\RouterFactory;
use Routir\Compiler\CompileConfig;
use Routir\Compiler\Compiler;
use Routir\Contracts\RouterMode;
use Routir\Runtime\MinimalResponseEmitter;
use Routir\Runtime\MinimalServerRequest;

use function Examples\Shared\createContainer;

$outputDir = dirname(__DIR__, 2) . '/tmp/example-compiled';
$config = new CompileConfig(
    inputs: __DIR__ . '/../routes.php',
    outputDir: $outputDir,
    mode: RouterMode::Compiled,
    optimizationLevel: 2,
);

if (!is_file($outputDir . '/current/manifest.json')) {
    new Compiler()->compile($config);
}

$router = RouterFactory::fromConfig(
    config: $config,
    container: createContainer(),
    responseAdapter: new JsonResponseAdapter(),
);

$response = $router->dispatch(MinimalServerRequest::fromGlobals());
MinimalResponseEmitter::emit($response);
