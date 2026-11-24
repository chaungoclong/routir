<?php

declare(strict_types=1);

namespace Routir\Runtime;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class MinimalPsr17Factory implements ResponseFactoryInterface, StreamFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new MinimalResponse($code, $reasonPhrase);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new MinimalStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $content = file_get_contents($filename);

        return new MinimalStream($content === false ? '' : $content);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        $content = stream_get_contents($resource);

        return new MinimalStream($content === false ? '' : $content);
    }
}
