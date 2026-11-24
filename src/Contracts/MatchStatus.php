<?php

declare(strict_types=1);

namespace Routir\Contracts;

enum MatchStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case OptionsSynthesized = 'options_synthesized';
    case InvalidRequestTarget = 'invalid_request_target';
}
