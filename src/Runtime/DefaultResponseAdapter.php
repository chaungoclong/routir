<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Routir\Contracts\ResponseAdapterInterface;
use Routir\Exception\DispatchException;

final class DefaultResponseAdapter implements ResponseAdapterInterface
{
    public function __construct(
        private readonly MinimalPsr17Factory $factory = new MinimalPsr17Factory(),
    ) {
    }

    /**
     * Convert a handler return value into a PSR-7 ResponseInterface.
     *
     * @param mixed $result Raw handler return value.
     * @param ServerRequestInterface $request Current HTTP request.
     *
     * @return ResponseInterface
     *
     * @throws DispatchException When the return value type is unsupported.
     */
    public function adapt(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (is_string($result)) {
            $response = $this->factory->createResponse(200);
            $response->getBody()->write($result);

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        throw new DispatchException(sprintf(
            'Unsupported handler return type "%s"; expected ResponseInterface or string, or configure a custom ResponseAdapterInterface.',
            get_debug_type($result),
        ));
    }
}
