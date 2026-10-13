<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

test('Sail bootstrap creates a development environment only when absent and preserves existing data', function (?string $existingEnvironment, int $dockerExitCode): void {
    $directory = sys_get_temp_dir().'/sail-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($directory);
    mkdir($directory.'/bin');
    mkdir($directory.'/database');
    file_put_contents($directory.'/composer.json', '{}');
    file_put_contents($directory.'/composer.lock', '{}');
    $sailEnvironment = "DB_CONNECTION=pgsql\nDB_DATABASE=laravel\nAPP_KEY=\n";
    file_put_contents($directory.'/.env.sail.example', $sailEnvironment);
    file_put_contents($directory.'/database/database.sqlite', 'existing database bytes');
    file_put_contents($directory.'/bin/docker', "#!/bin/sh\nprintf called > docker.called\nexit \"\$DOCKER_EXIT_CODE\"\n");
    chmod($directory.'/bin/docker', 0755);

    if ($existingEnvironment !== null) {
        file_put_contents($directory.'/.env', $existingEnvironment);
    }

    $process = new Process(['sh', base_path('sail-bootstrap')], $directory, [
        'PATH' => $directory.'/bin:'.getenv('PATH'),
        'DOCKER_EXIT_CODE' => (string) $dockerExitCode,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe($dockerExitCode);
        expect(file_get_contents($directory.'/.env'))->toBe($existingEnvironment ?? $sailEnvironment);
        expect(file_get_contents($directory.'/database/database.sqlite'))->toBe('existing database bytes');
        expect(file_get_contents($directory.'/docker.called'))->toBe('called');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    'new clone' => [null, 0],
    'existing key and SQLite configuration' => ["DB_CONNECTION=sqlite\nAPP_KEY=existing-key\n", 0],
    'Docker failure preserves environment and returns its status' => ["DB_CONNECTION=pgsql\nAPP_KEY=existing-key\n", 42],
]);

test('Sail bootstrap rejects a directory without the lockfile before changing its environment', function (): void {
    $directory = sys_get_temp_dir().'/sail-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory.'/composer.json', '{}');
    file_put_contents($directory.'/.env.sail.example', "DB_CONNECTION=pgsql\n");
    $process = new Process(['sh', base_path('sail-bootstrap')], $directory);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(1);
        expect($process->getErrorOutput())->toContain('Run this command from the project root with its committed Composer lockfile.');
        expect(file_exists($directory.'/.env'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
