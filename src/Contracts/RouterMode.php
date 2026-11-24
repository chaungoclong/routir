<?php

declare(strict_types=1);

namespace Routir\Contracts;

enum RouterMode: string
{
    case Interpreted = 'interpreted';
    case Compiled = 'compiled';
    case Auto = 'auto';
}
