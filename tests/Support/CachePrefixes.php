<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

class CachePrefixes
{
    /** @return array{cache: string, redis: string} */
    public static function forRuntime(string $applicationName, string $environment, ?string $cacheOverride = null, ?string $redisOverride = null): array
    {
        $environmentDirectory = sys_get_temp_dir().'/cache-prefixes-'.bin2hex(random_bytes(8));
        mkdir($environmentDirectory);
        $process = new Process([PHP_BINARY, '-r', <<<'SCRIPT'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->useEnvironmentPath($argv[1]);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['cache' => config('cache.prefix'), 'redis' => config('database.redis.options.prefix')], JSON_THROW_ON_ERROR);
SCRIPT
            , $environmentDirectory,
        ], base_path(), [
            'APP_NAME' => $applicationName,
            'APP_ENV' => $environment,
            'CACHE_PREFIX' => $cacheOverride ?? false,
            'REDIS_PREFIX' => $redisOverride ?? false,
        ]);
        try {
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            rmdir($environmentDirectory);
        }
    }
}
