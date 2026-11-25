<?php

declare(strict_types=1);

namespace Routir\Benchmarks\Model;

/**
 * Categorizes generated benchmark route shapes.
 */
enum RouteType: string
{
    case StaticRoute = 'static';
    case IntParam = 'int';
    case MultiParam = 'multi';
}
