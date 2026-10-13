<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

class ValkeySmokeJob implements ShouldQueue
{
    public function handle(): void
    {
        Cache::store('redis')->put('processed-job', 'processed', 60);
    }
}

$mode = $argv[1];
if ($mode === 'rejected') {
    try {
        Cache::store('redis')->put('must-not-connect', 'rejected', 60);
    } catch (RedisException) {
        echo "Expected TLS/authentication boundary rejected the connection.\n";
        exit(0);
    }
    throw new RuntimeException('An invalid TLS/authentication connection was accepted.');
}

if (config('cache.default') !== ($mode === 'cache' ? 'redis' : 'database') ||
    config('session.driver') !== ($mode === 'session' ? 'redis' : 'database') ||
    config('queue.default') !== ($mode === 'queue' ? 'redis' : 'database')) {
    throw new RuntimeException('Driver selection changed unrelated stores.');
}

$cache = Cache::store('redis');
if ($mode === 'cache') {
    $cache->put('cache-round-trip', ['value' => 42], 60);
    $ttl = Redis::connection('cache')->ttl(config('cache.prefix').'cache-round-trip');
    if ($cache->get('cache-round-trip') !== ['value' => 42] || $ttl <= 0 || $ttl > 60 ||
        ! ($app['session']->driver()->getHandler() instanceof DatabaseSessionHandler) ||
        ! ($app['queue']->connection() instanceof DatabaseQueue)) {
        throw new RuntimeException('Cache TTL/round trip or independent defaults failed.');
    }
    $cache->forget('cache-round-trip');
    if ($cache->get('cache-round-trip') !== null) {
        throw new RuntimeException('Cache invalidation failed.');
    }
} elseif ($mode === 'session') {
    $session = $app['session']->driver();
    $session->start();
    $session->put('valkey-proof', 'session-value');
    $session->save();
    $resumed = (new SessionManager($app))->driver();
    $resumed->setId($session->getId());
    $resumed->start();
    if ($resumed->get('valkey-proof') !== 'session-value' || ! ($app['queue']->connection() instanceof DatabaseQueue)) {
        throw new RuntimeException('Independent Redis session round trip failed.');
    }
    $resumed->getHandler()->destroy($session->getId());
} elseif ($mode === 'queue') {
    $queue = $app['queue']->connection();
    if (! ($queue instanceof RedisQueue) || ! ($app['session']->driver()->getHandler() instanceof DatabaseSessionHandler)) {
        throw new RuntimeException('Independent Redis queue selection failed.');
    }
    $queue->push(new ValkeySmokeJob, queue: 'valkey-proof');
    $job = $queue->pop('valkey-proof');
    if ($job === null) {
        throw new RuntimeException('Native queued job was not reserved.');
    }
    $job->fire();
    $job->delete();
    if ($cache->get('processed-job') !== 'processed' || $queue->size('valkey-proof') !== 0) {
        throw new RuntimeException('Native queued job processing/deletion failed.');
    }
    $cache->forget('processed-job');
} else {
    throw new RuntimeException('Unknown disposable fixture mode.');
}

echo "Native {$mode} TLS/ACL round trip and independent driver selection passed.\n";
