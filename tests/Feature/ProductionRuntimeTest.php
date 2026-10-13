<?php

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

function productionRuntimeEnvironment(string $directory): array
{
    return [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'APP_URL' => 'https://template.example.invalid',
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'APP_SERVICES_CACHE' => $directory.'/services.php',
        'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        'SESSION_DRIVER' => 'file',
        'SESSION_SECURE_COOKIE' => 'true',
        'TRUSTED_PROXIES' => '',
        'DB_PASSWORD' => 'runtime-secret-canary',
        'XDEBUG_MODE' => 'off',
    ];
}

test('runtime preparation caches injected configuration and routes without exposing its secrets', function () {
    $directory = sys_get_temp_dir().'/production-runtime-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        $environment = productionRuntimeEnvironment($directory);
        $environment['DB_SSLMODE'] = 'verify-full';
        $environment['DB_SSLROOTCERT'] = '/run/rds-ca.pem';
        $result = Process::path(base_path())->env($environment)->run([PHP_BINARY, 'docker/php/prepare-runtime.php']);
        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
        expect($result->output().$result->errorOutput())->not->toContain('runtime-secret-canary', $environment['APP_KEY']);
        $configuration = require $directory.'/config.php';
        expect($configuration['app']['key'])->toBe($environment['APP_KEY'])
            ->and($configuration['app']['url'])->toBe($environment['APP_URL'])
            ->and($configuration['session']['secure'])->toBeTrue();
        expect(is_file($directory.'/routes.php'))->toBeTrue();
        $connection = $configuration['database']['connections']['pgsql'];
        expect($connection['sslrootcert'] ?? null)->toBe('/run/rds-ca.pem');
        $dsn = (new ReflectionMethod(PostgresConnector::class, 'getDsn'))
            ->invoke(new PostgresConnector, $connection);
        expect($dsn)->toContain(';sslmode=verify-full', ';sslrootcert=/run/rds-ca.pem');

        $environment['APP_URL'] = 'https://promoted.example.invalid';
        $environment['TRUSTED_PROXIES'] = '10.0.0.2, fd00::/64';
        $rerun = Process::path(base_path())->env($environment)->run([PHP_BINARY, 'docker/php/prepare-runtime.php']);
        expect($rerun->exitCode())->toBe(0, $rerun->output().$rerun->errorOutput());
        $configuration = require $directory.'/config.php';
        expect($configuration['app']['key'])->toBe($environment['APP_KEY'])
            ->and($configuration['app']['url'])->toBe('https://promoted.example.invalid')
            ->and($configuration['trustedproxy']['proxies'])->toBe(['10.0.0.2', 'fd00::/64']);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

test('an unconfigured EC2 application rejects forwarded headers even for a cloud-looking host', function () {
    Route::get('/runtime-request', fn () => response()->json(['secure' => request()->isSecure(), 'ip' => request()->ip()]));

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
        ->withHeaders(['Host' => 'spoof.on-forge.com', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.1'])
        ->get('http://spoof.on-forge.com/runtime-request')
        ->assertOk()
        ->assertExactJson(['secure' => false, 'ip' => '198.51.100.10']);
});

test('invalid production configuration fails closed without printing injected secrets', function (array $overrides) {
    $directory = sys_get_temp_dir().'/production-runtime-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        $environment = array_replace(productionRuntimeEnvironment($directory), $overrides);
        $result = Process::path(base_path())->env($environment)->run([PHP_BINARY, 'docker/php/prepare-runtime.php']);

        expect($result->exitCode())->toBe(1);
        expect($result->output().$result->errorOutput())->not->toContain('runtime-secret-canary');
        expect(is_file($directory.'/routes.php'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    'local runtime' => [['APP_ENV' => 'local']],
    'debug enabled' => [['APP_DEBUG' => 'true']],
    'missing key' => [['APP_KEY' => '']],
    'invalid key' => [['APP_KEY' => 'runtime-secret-canary']],
    'HTTP URL' => [['APP_URL' => 'http://template.example.invalid']],
    'missing URL' => [['APP_URL' => '']],
    'insecure cookie' => [['SESSION_SECURE_COOKIE' => 'false']],
    'wildcard proxy' => [['TRUSTED_PROXIES' => '*']],
    'calling-address proxy' => [['TRUSTED_PROXIES' => 'REMOTE_ADDR']],
    'unbounded IPv4 proxy' => [['TRUSTED_PROXIES' => '0.0.0.0/0']],
    'unbounded IPv6 proxy' => [['TRUSTED_PROXIES' => '::/0']],
    'invalid CIDR mask' => [['TRUSTED_PROXIES' => '10.0.0.1/33']],
    'hostname proxy' => [['TRUSTED_PROXIES' => 'proxy.example.invalid']],
]);

test('only explicitly trusted proxy addresses can assert HTTPS or the original client IP', function (string $address, bool $secure, string $client) {
    config(['trustedproxy.proxies' => ['10.0.0.2', 'fd00::/64']]);
    Route::get('/runtime-request', fn () => response()->json(['secure' => request()->isSecure(), 'ip' => request()->ip(), 'host' => request()->host()]));

    $this->withServerVariables(['REMOTE_ADDR' => $address])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.1', 'X-Forwarded-Host' => 'attacker.example.invalid'])
        ->get('/runtime-request')
        ->assertOk()
        ->assertExactJson(['secure' => $secure, 'ip' => $client, 'host' => 'localhost']);
})->with([
    'listed IPv4' => ['10.0.0.2', true, '203.0.113.1'],
    'listed IPv6 CIDR' => ['fd00::3', true, '203.0.113.1'],
    'untrusted address' => ['198.51.100.2', false, '198.51.100.2'],
]);
