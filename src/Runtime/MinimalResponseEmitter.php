<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\ResponseInterface;

final class MinimalResponseEmitter
{
    public static function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            $reason = $response->getReasonPhrase();
            header(sprintf(
                'HTTP/%s %d%s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                $reason !== '' ? ' ' . $reason : '',
            ), true, $response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), $first);
                    $first = false;
                }
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        echo (string) $body;
    }
}
