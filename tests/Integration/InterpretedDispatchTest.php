<?php

declare(strict_types=1);

use Routir\API\Router;
use Routir\DependencyInjection\SimpleContainer;
use Routir\Runtime\MinimalResponse;
use Routir\Runtime\MinimalServerRequest;
use Routir\Runtime\MinimalStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

test('dispatches handler with service and int conversion', function (): void {
    $service = new class {
        public function format(int $id): string
        {
            return 'user:' . $id;
        }
    };
    $container = new SimpleContainer([
        $service::class => $service,
    ]);

    $router = new Router(container: $container);
    $router->get('/users/{id:int}', static fn (int $id, ?object $svc = null): string => 'id=' . $id);
    $router->get('/svc/{id:int}', [$service, 'format']);

    $res = $router->dispatch(new MinimalServerRequest('GET', '/svc/42'));
    expect($res->getStatusCode())->toBe(200)
        ->and((string) $res->getBody())->toBe('user:42');
});

test('middleware order request replacement short circuit and repeated next', function (): void {
    /** @var ArrayObject<int, string> $trace */
    $trace = new ArrayObject();

    $outer = new class ($trace) implements MiddlewareInterface {
        /** @param ArrayObject<int, string> $trace */
        public function __construct(private ArrayObject $trace) {}
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->trace[] = 'outer:before';
            $res = $handler->handle($request->withAttribute('role', 'admin'));
            $this->trace[] = 'outer:after';

            return $res->withHeader('X-Outer', '1');
        }
    };

    $repeat = new class ($trace) implements MiddlewareInterface {
        /** @param ArrayObject<int, string> $trace */
        public function __construct(private ArrayObject $trace) {}
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            $this->trace[] = 'repeat:1';
            $handler->handle($request);
            $this->trace[] = 'repeat:2';

            return $handler->handle($request);
        }
    };

    $shortCircuit = new class implements MiddlewareInterface {
        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
        {
            return new MinimalResponse(403, body: new MinimalStream('forbidden'));
        }
    };

    $container = new SimpleContainer([
        'outer' => $outer,
        'repeat' => $repeat,
        'short' => $shortCircuit,
    ]);

    $router = new Router(container: $container);
    $router->get('/hello', static function (ServerRequestInterface $req) use ($trace): string {
        $role = $req->getAttribute('role', 'none');
        $trace[] = 'handler:' . (is_string($role) ? $role : 'none');

        return 'ok';
    })->middleware(['outer', 'repeat']);

    $router->get('/blocked', static fn (): string => 'never')->middleware('short');

    $res = $router->dispatch(new MinimalServerRequest('GET', '/hello'));
    expect($res->getStatusCode())->toBe(200)
        ->and((string) $res->getBody())->toBe('ok')
        ->and($res->getHeaderLine('X-Outer'))->toBe('1')
        ->and($trace->getArrayCopy())->toBe([
            'outer:before',
            'repeat:1',
            'handler:admin',
            'repeat:2',
            'handler:admin',
            'outer:after',
        ]);

    $blocked = $router->dispatch(new MinimalServerRequest('GET', '/blocked'));
    expect($blocked->getStatusCode())->toBe(403)
        ->and((string) $blocked->getBody())->toBe('forbidden');
});

test('fallback head options 405 and 400', function (): void {
    $router = new Router();
    $router->get('/items', static fn (): string => 'item-list');
    $router->fallback(static fn (): ResponseInterface => new MinimalResponse(404, body: new MinimalStream('custom-404')));

    $head = $router->dispatch(new MinimalServerRequest('HEAD', '/items'));
    expect($head->getStatusCode())->toBe(200)
        ->and((string) $head->getBody())->toBe('')
        ->and($head->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8');

    $opts = $router->dispatch(new MinimalServerRequest('OPTIONS', '/items'));
    expect($opts->getStatusCode())->toBe(204)
        ->and($opts->getHeaderLine('Allow'))->toBe('GET, HEAD, OPTIONS');

    $mna = $router->dispatch(new MinimalServerRequest('POST', '/items'));
    expect($mna->getStatusCode())->toBe(405)
        ->and($mna->getHeaderLine('Allow'))->toBe('GET, HEAD, OPTIONS');

    $notFound = $router->dispatch(new MinimalServerRequest('GET', '/nope'));
    expect($notFound->getStatusCode())->toBe(404)
        ->and((string) $notFound->getBody())->toBe('custom-404');

    $bad = $router->dispatch(new MinimalServerRequest('GET', '/%ZZ'));
    expect($bad->getStatusCode())->toBe(400);
});
