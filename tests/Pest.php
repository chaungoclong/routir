<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(PHPUnit\Framework\TestCase::class)
    ->in('Unit', 'Integration', 'Differential', 'Golden', 'Fuzz');
