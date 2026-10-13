<?php

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

test('readiness is stateless and succeeds when its dependencies are available', function () {
    $this->getJson('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ready'])
        ->assertHeaderMissing('Set-Cookie');
});

test('dependency failure changes readiness without changing boot liveness or exposing diagnostics', function () {
    $originalConnection = config('database.default');
    config([
        'database.connections.unavailable' => [
            'driver' => 'sqlite',
            'database' => '/missing/runtime-secret-canary.sqlite',
        ],
        'database.default' => 'unavailable',
    ]);

    try {
        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable'])
            ->assertHeaderMissing('Set-Cookie');
        $this->get('/up')->assertOk();
    } finally {
        config(['database.default' => $originalConnection]);
    }
});

test('readiness includes the selected cache dependency', function () {
    Cache::shouldReceive('get')
        ->once()
        ->with('runtime:readiness')
        ->andThrow(new RuntimeException('cache-password-secret-canary'));

    $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
});

test('readiness includes independently selected Redis session and queue dependencies', function (string $setting) {
    config([$setting => 'redis']);
    Redis::shouldReceive('connection')
        ->once()
        ->andThrow(new RuntimeException('redis-password-secret-canary'));

    $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
})->with(['session.driver', 'queue.default']);

test('readiness probes independently configured database session and queue connections', function (string $driverSetting, string $connectionSetting, bool $available) {
    config([
        $driverSetting => 'database',
        $connectionSetting => 'readiness-probe',
        'database.connections.readiness-probe' => [
            'driver' => 'sqlite',
            'database' => $available ? ':memory:' : '/missing/runtime-secret-canary.sqlite',
        ],
    ]);

    $this->getJson('/ready')
        ->assertStatus($available ? 200 : 503)
        ->assertExactJson(['status' => $available ? 'ready' : 'unavailable']);
})->with([
    'healthy session database' => ['session.driver', 'session.connection', true],
    'failed session database' => ['session.driver', 'session.connection', false],
    'healthy queue database' => ['queue.default', 'queue.connections.database.connection', true],
    'failed queue database' => ['queue.default', 'queue.connections.database.connection', false],
]);

test('database session and queue readiness can reuse the default database connection', function () {
    config(['session.driver' => 'database', 'session.connection' => null, 'queue.default' => 'database', 'queue.connections.database.connection' => null]);

    $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready']);
});

test('healthy selected Redis connections satisfy readiness independently', function (string $driverSetting, string $connectionSetting) {
    config([$driverSetting => 'redis', $connectionSetting => 'readiness-probe']);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('ping')->once()->andReturn('PONG');
    Redis::shouldReceive('connection')->once()->with('readiness-probe')->andReturn($connection);

    $this->getJson('/ready')->assertOk()->assertExactJson(['status' => 'ready']);
})->with([
    'session' => ['session.driver', 'session.connection'],
    'queue' => ['queue.default', 'queue.connections.redis.connection'],
]);
