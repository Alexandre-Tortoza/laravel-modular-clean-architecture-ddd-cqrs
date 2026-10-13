<?php

use Illuminate\Support\Facades\Process;

test('release migrations reject invalid operational inputs without exposing credentials', function (array $overrides, string $message) {
    $result = Process::path(base_path())->env(array_replace([
        'RELEASE_REVISION' => str_repeat('a', 40),
        'RELEASE_MIGRATION_LOCK_TIMEOUT' => '60',
        'DB_PASSWORD' => 'release-secret-canary',
        'XDEBUG_MODE' => 'off',
    ], $overrides))->run([PHP_BINARY, 'docker/php/release-migrate.php']);

    expect($result->exitCode())->toBe(1);
    expect($result->errorOutput())->toContain($message)->not->toContain('release-secret-canary');
})->with([
    'missing source' => [['RELEASE_REVISION' => ''], 'full source revision'],
    'mutable version' => [['RELEASE_REVISION' => 'latest'], 'full source revision'],
    'invalid timeout' => [['RELEASE_MIGRATION_LOCK_TIMEOUT' => '-1'], 'lock timeout'],
    'unbounded timeout' => [['RELEASE_MIGRATION_LOCK_TIMEOUT' => '3601'], 'lock timeout'],
    'fractional timeout' => [['RELEASE_MIGRATION_LOCK_TIMEOUT' => '1.5'], 'lock timeout'],
]);
