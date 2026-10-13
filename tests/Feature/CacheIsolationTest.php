<?php

use Illuminate\Support\Facades\Cache;
use Tests\Support\CachePrefixes;

test('a cached entry is isolated from other applications and environments by default', function (): void {
    $local = CachePrefixes::forRuntime('Roadmap Template', 'local');
    $testing = CachePrefixes::forRuntime('Roadmap Template', 'testing');
    $otherApplication = CachePrefixes::forRuntime('Another Template', 'local');
    $localCache = Cache::build([...config('cache.stores.database'), 'prefix' => $local['cache']]);
    $testingCache = Cache::build([...config('cache.stores.database'), 'prefix' => $testing['cache']]);
    $otherCache = Cache::build([...config('cache.stores.database'), 'prefix' => $otherApplication['cache']]);

    $localCache->put('dashboard-summary', ['active_teams' => 3], 60);

    expect($localCache->get('dashboard-summary'))->toBe(['active_teams' => 3]);
    expect($testingCache->get('dashboard-summary'))->toBeNull();
    expect($otherCache->get('dashboard-summary'))->toBeNull();
    expect($testing['redis'])->not->toBe($local['redis']);
    expect($otherApplication['redis'])->not->toBe($local['redis']);
});

test('explicit cache and Redis prefix overrides remain stable across environments', function (): void {
    $prefixes = CachePrefixes::forRuntime('Roadmap Template', 'testing', 'explicit-cache-', 'explicit-redis-');

    expect($prefixes)->toBe(['cache' => 'explicit-cache-', 'redis' => 'explicit-redis-']);
});
