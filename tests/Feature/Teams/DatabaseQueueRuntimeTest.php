<?php

use Illuminate\Support\Facades\Process;

test('database worker sends queued invitations after commit and discards rolled back notifications without Redis', function () {
    $database = tempnam(sys_get_temp_dir(), 'queue-runtime-');

    try {
        $result = Process::path(base_path())->env([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'MAIL_MAILER' => 'array',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '1',
        ])->run([PHP_BINARY, 'tests/Docker/queue-runtime.php']);

        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
        expect(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'driver' => 'database',
            'before_rollback' => 0,
            'after_rollback' => 0,
            'before_commit' => 0,
            'after_commit' => 1,
            'worker_exit' => 0,
            'remaining_jobs' => 0,
            'failed_jobs' => 0,
            'mail_count' => 1,
        ]);
    } finally {
        unlink($database);
    }
});
