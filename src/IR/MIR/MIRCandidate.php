<?php

declare(strict_types=1);

namespace Routir\IR\MIR;

use Routir\Semantic\NormalizedSegment;

final readonly class MIRCandidate
{
    /**
     * @param list<NormalizedSegment> $segments
     * @param array<string, string> $defaults
     */
    public function __construct(
        public int $routeId,
        public array $segments,
        public array $defaults,
        public bool $isStatic,
        public bool $omittedOptional,
        public bool $hasWildcard,
        public int $registrationOrder,
    ) {
    }
}
