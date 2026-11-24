<?php

declare(strict_types=1);

/**
 * Execute the Routir CLI binary with the given arguments.
 *
 * @param list<string> $args CLI arguments.
 *
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runRoutirCli(array $args): array
{
    $bin = dirname(__DIR__, 2) . '/bin/routir';
    $cmd = array_merge([PHP_BINARY, $bin], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to launch bin/routir.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    return [
        'exitCode' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

test('cli displays help and rejects unknown command', function (): void {
    $help = runRoutirCli(['--help']);
    expect($help['exitCode'])->toBe(0)
        ->and($help['stdout'])->toContain('Routir CLI')
        ->and($help['stdout'])->toContain('routir build');

    $unknown = runRoutirCli(['nope']);
    expect($unknown['exitCode'])->toBe(1)
        ->and($unknown['stderr'])->toContain('Unknown command: nope');
});

test('cli dump:tokens and dump:ast work and report errors on missing pattern', function (): void {
    $toks = runRoutirCli(['dump:tokens', '/users/{id:int}']);
    expect($toks['exitCode'])->toBe(0)
        ->and($toks['stdout'])->toContain('Slash')
        ->and($toks['stdout'])->toContain('LeftBrace')
        ->and($toks['stdout'])->toContain('EOF');

    $ast = runRoutirCli(['dump:ast', '/users/{id:int}']);
    expect($ast['exitCode'])->toBe(0)
        ->and($ast['stdout'])->toContain('RouteNode("/users/{id:int}")')
        ->and($ast['stdout'])->toContain('LiteralNode("users")')
        ->and($ast['stdout'])->toContain('ParameterNode(name=id, constraint=int');

    $err = runRoutirCli(['dump:tokens']);
    expect($err['exitCode'])->toBe(1)
        ->and($err['stderr'])->toContain('pattern argument required');
});

test('cli check, list, dump:ir, dump:code, build and clear work end to end', function (): void {
    $base = sys_get_temp_dir() . '/routir_cli_test_' . uniqid('', true);
    $routesFile = $base . '/routes.php';
    $outDir = $base . '/out';
    $cfgFile = $base . '/routir.php';
    mkdir($base, 0755, true);

    file_put_contents($routesFile, "<?php\n\$router->get('/ping', static fn (): string => 'pong')->name('ping');\n\$router->get('/users/{id:int}', static fn (int \$id): string => (string) \$id)->name('users.show');\n");
    file_put_contents($cfgFile, "<?php\nreturn ['input' => " . var_export($routesFile, true) . ", 'output' => " . var_export($outDir, true) . ", 'optimizationLevel' => 2];\n");

    try {
        $check = runRoutirCli(['check', '--input=' . $routesFile]);
        expect($check['exitCode'])->toBe(0)
            ->and($check['stdout'])->toContain('OK: 2 routes validated');

        $list = runRoutirCli(['list', $routesFile]);
        expect($list['exitCode'])->toBe(0)
            ->and($list['stdout'])->toContain('/ping')
            ->and($list['stdout'])->toContain('name=users.show');

        $hir = runRoutirCli(['dump:ir', $routesFile, '--stage=hir']);
        expect($hir['exitCode'])->toBe(0)
            ->and($hir['stdout'])->toContain('HIRRoute #0');

        $mir = runRoutirCli(['dump:ir', $routesFile, '--stage=mir']);
        expect($mir['exitCode'])->toBe(0)
            ->and($mir['stdout'])->toContain('MIR Method GET');

        $lir = runRoutirCli(['dump:ir', $routesFile, '--stage=lir', '-O2']);
        expect($lir['exitCode'])->toBe(0)
            ->and($lir['stdout'])->toContain('static_table GET');

        $code = runRoutirCli(['dump:code', $routesFile, '-O2']);
        expect($code['exitCode'])->toBe(0)
            ->and($code['stdout'])->toContain('STATIC_TABLES');

        $build = runRoutirCli(['build', '--config=' . $cfgFile]);
        expect($build['exitCode'])->toBe(0)
            ->and($build['stdout'])->toContain('Built ')
            ->and(is_file($outDir . '/current/manifest.json'))->toBeTrue();

        $clear = runRoutirCli(['clear', '--config=' . $cfgFile]);
        expect($clear['exitCode'])->toBe(0)
            ->and($clear['stdout'])->toContain('Cleared managed artifacts')
            ->and(is_dir($outDir . '/releases'))->toBeFalse();
    } finally {
        exec('rm -rf ' . escapeshellarg($base));
    }
});

test('cli returns nonzero exit code when route file has semantic error', function (): void {
    $badFile = sys_get_temp_dir() . '/routir_cli_bad_' . uniqid('', true) . '.php';
    file_put_contents($badFile, "<?php\n\$router->get('/a', static fn (): string => 'a')->name('dup');\n\$router->get('/b', static fn (): string => 'b')->name('dup');\n");

    try {
        $res = runRoutirCli(['check', $badFile]);
        expect($res['exitCode'])->toBe(1)
            ->and($res['stderr'])->toContain('RC_SEM_DUPLICATE_NAME');
    } finally {
        if (is_file($badFile)) {
            unlink($badFile);
        }
    }
});
