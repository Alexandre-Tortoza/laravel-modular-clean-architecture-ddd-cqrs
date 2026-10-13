<?php

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
