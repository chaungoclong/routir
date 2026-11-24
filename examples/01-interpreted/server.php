<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../bootstrap.php';

use Examples\Shared\JsonResponseAdapter;
use Routir\API\RouterFactory;
use Routir\Contracts\RouterMode;
use Routir\Runtime\MinimalResponseEmitter;
use Routir\Runtime\MinimalServerRequest;

use function Examples\Shared\createContainer;

$router = RouterFactory::create(
    mode: RouterMode::Interpreted,
    routes: __DIR__ . '/../routes.php',
    container: createContainer(),
    responseAdapter: new JsonResponseAdapter(),
);

$response = $router->dispatch(MinimalServerRequest::fromGlobals());
MinimalResponseEmitter::emit($response);
