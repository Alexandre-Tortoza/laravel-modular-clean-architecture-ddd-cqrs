<?php

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\CachePrefixes;
use Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function (): void {
    if (getenv('RUN_REDIS_TESTS') !== '1') {
        $this->markTestSkipped('Opt in with RUN_REDIS_TESTS=1 against disposable Redis and PostgreSQL testing databases.');
    }

    expect(config('database.default'))->toBe('pgsql');
    expect(config('cache.default'))->toBe('redis');
    expect(config('session.driver'))->toBe('database');
    expect(config('queue.default'))->toBe('database');
    expect((int) config('database.redis.cache.database'))->toBe(15);
    expect(config('database.connections.pgsql.database'))->toBe('testing');
    expect(config('database.redis.options.prefix'))->toMatch('/^redis-tests-[a-f0-9-]{36}-$/');
});

test('Redis cache stores a real entry with a TTL and invalidates only that entry', function (): void {
    $cache = Cache::store('redis');
    $connection = Redis::connection('cache');
    $key = 'dashboard-summary-'.bin2hex(random_bytes(16));

    try {
        $cache->put($key, ['active_teams' => 3], 60);

        expect($cache->get($key))->toBe(['active_teams' => 3]);
        expect(app('session')->driver()->getHandler())->toBeInstanceOf(DatabaseSessionHandler::class);
        expect(app('queue')->connection())->toBeInstanceOf(DatabaseQueue::class);
        expect($connection->ttl(config('cache.prefix').$key))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
        expect($cache->forget($key))->toBeTrue();
        expect($cache->get($key))->toBeNull();
        expect($connection->exists(config('cache.prefix').$key))->toBe(0);
    } finally {
        $cache->forget($key);
    }
})->group('redis');

test('Redis cached values stay isolated across applications and environments', function (): void {
    $local = CachePrefixes::forRuntime('Roadmap Template', 'local');
    $testing = CachePrefixes::forRuntime('Roadmap Template', 'testing');
    $other = CachePrefixes::forRuntime('Another Template', 'local');
    $localCache = Cache::build([...config('cache.stores.redis'), 'prefix' => $local['cache']]);
    $testingCache = Cache::build([...config('cache.stores.redis'), 'prefix' => $testing['cache']]);
    $otherCache = Cache::build([...config('cache.stores.redis'), 'prefix' => $other['cache']]);
    $key = 'dashboard-summary-'.bin2hex(random_bytes(16));

    try {
        $localCache->put($key, ['active_teams' => 3], 60);

        expect($localCache->get($key))->toBe(['active_teams' => 3]);
        expect($testingCache->get($key))->toBeNull();
        expect($otherCache->get($key))->toBeNull();
    } finally {
        $localCache->forget($key);
    }
})->group('redis');

test('choosing an unavailable Redis cache throws explicitly and does not fall back to the database', function (): void {
    $key = 'unavailable-redis-probe-'.bin2hex(random_bytes(16));
    $process = new Process([PHP_BINARY, '-r', <<<'SCRIPT'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Cache::put($argv[1], 'must not fall back', 60);
SCRIPT
        , $key,
    ], base_path(), [
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PORT' => '1',
        'REDIS_URL' => false,
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput().$process->getOutput())->toContain('RedisException');
    expect(Cache::store('database')->get($key))->toBeNull();
    expect(config('cache.default'))->toBe('redis');
})->group('redis');
