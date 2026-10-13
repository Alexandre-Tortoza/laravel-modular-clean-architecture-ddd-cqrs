<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Process;

test('managed Valkey TLS retains verified peers credentials and separate logical databases in cached native configuration', function (string $username, string $encodedUsername) {
    $directory = sys_get_temp_dir().'/managed-valkey-config-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $environment = [
        'APP_ENV' => 'production',
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_SERVICES_CACHE' => $directory.'/services.php',
        'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        'REDIS_URL' => 'rediss://fixture.cache.amazonaws.com:6379',
        'REDIS_USERNAME' => $encodedUsername,
        'REDIS_PASSWORD' => 'synthetic-private-credential',
        'REDIS_DB' => '0',
        'REDIS_CACHE_DB' => '1',
        'CACHE_STORE' => 'redis',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'XDEBUG_MODE' => 'off',
    ];

    try {
        $result = Process::path(base_path())->env($environment)->run([PHP_BINARY, 'artisan', 'config:cache', '--no-interaction']);

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
        expect($result->output().$result->errorOutput())->not->toContain($environment['REDIS_PASSWORD']);
        $configuration = require $directory.'/config.php';
        foreach (['default' => '0', 'cache' => '1'] as $connection => $database) {
            $parsed = (new ConfigurationUrlParser)->parseConfiguration($configuration['database']['redis'][$connection]);
            expect($parsed['driver'])->toBe('tls')
                ->and($parsed['host'])->toBe('fixture.cache.amazonaws.com')
                ->and($parsed['database'])->toBe($database)
                ->and($parsed['username'])->toBe($username)
                ->and($parsed['password'])->toBe($environment['REDIS_PASSWORD'])
                ->and($parsed['context'] ?? null)->toBe(['stream' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        }
        expect($configuration['cache']['default'])->toBe('redis')
            ->and($configuration['session']['driver'])->toBe('database')
            ->and($configuration['queue']['default'])->toBe('database');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    'ordinary username' => ['fixture_app', 'fixture_app'],
    'reserved literal username' => ['null', '"null"'],
]);

test('Redis TLS context is enabled only for encrypted URLs so native TCP stays available', function (string $url, ?array $expected) {
    $environment = $_ENV;
    $server = $_SERVER;
    $_ENV['REDIS_URL'] = $url;
    $_SERVER['REDIS_URL'] = $url;

    try {
        $configuration = require base_path('config/database.php');

        expect($configuration['redis']['default']['context'] ?? null)->toBe($expected)
            ->and($configuration['redis']['cache']['context'] ?? null)->toBe($expected);
    } finally {
        $_ENV = $environment;
        $_SERVER = $server;
    }
})->with([
    'default TCP without URL' => ['', null],
    'explicit TCP URL' => ['redis://fixture.invalid:6379', null],
    'encrypted managed Valkey' => ['rediss://fixture.cache.amazonaws.com:6379', ['stream' => ['verify_peer' => true, 'verify_peer_name' => true]]],
]);
