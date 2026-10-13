<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Process;

test('managed Valkey TLS retains verified peers credentials and separate logical databases in cached native configuration', function () {
    $directory = sys_get_temp_dir().'/managed-valkey-config-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $environment = [
        'APP_ENV' => 'production',
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_SERVICES_CACHE' => $directory.'/services.php',
        'APP_PACKAGES_CACHE' => $directory.'/packages.php',
        'REDIS_URL' => 'rediss://fixture.cache.amazonaws.com:6379',
        'REDIS_USERNAME' => 'fixture_app',
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
                ->and($parsed['username'])->toBe('fixture_app')
                ->and($parsed['password'])->toBe($environment['REDIS_PASSWORD'])
                ->and($parsed['context'] ?? null)->toBe(['stream' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        }
        expect($configuration['cache']['default'])->toBe('redis')
            ->and($configuration['session']['driver'])->toBe('database')
            ->and($configuration['queue']['default'])->toBe('database');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
