<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

test('runtime benchmark requires manual dispatch and preserves equal resource limits', function () {
    $path = base_path('.github/workflows/runtime-benchmark.yml');
    expect(is_file($path))->toBeTrue();
    $workflow = Yaml::parseFile($path);
    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['jobs']['benchmark']['if'])->toBe("github.event_name == 'workflow_dispatch'");
    $job = $workflow['jobs']['benchmark'];
    expect($job)->not->toHaveKey('continue-on-error');
    foreach ($job['steps'] as $step) {
        expect($step)->not->toHaveKey('continue-on-error');
        if (isset($step['uses'])) {
            expect($step['uses'])->toMatch('/@[a-f0-9]{40}\z/');
        }
    }
    $compose = Yaml::parseFile(base_path('tests/Docker/runtime-benchmark.compose.yaml'));
    expect($compose['services']['php']['cpus'])->toBe(2)
        ->and($compose['services']['php']['mem_limit'])->toBe('512m')
        ->and($compose['services']['nginx']['cpus'])->toBe(0.5)
        ->and($compose['services']['nginx']['mem_limit'])->toBe('128m');
});

test('runtime benchmark rejects missing images before allocating disposable resources', function () {
    $result = Process::path(base_path())->run(['bash', 'tests/Docker/runtime-benchmark.sh']);
    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toBe("Provide FPM, Nginx, Octane and Octane proxy images, source SHA and an output directory.\n");
});

test('runtime benchmark rejects invalid limits or source SHA before allocation', function (string $revision, string $requests) {
    $output = base_path('storage/unallocated-benchmark');
    expect(file_exists($output))->toBeFalse();
    $result = Process::path(base_path())->env(['BENCH_REQUESTS' => $requests])->run([
        'bash', 'tests/Docker/runtime-benchmark.sh', 'fpm', 'nginx', 'octane', 'proxy', $revision, $output,
    ]);
    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toBe("Use a full source SHA, 32-4096 requests and a new output directory.\n")
        ->and(file_exists($output))->toBeFalse();
})->with([
    'excessive requests' => [str_repeat('a', 40), '4097'],
    'invalid source revision' => ['invalid', '256'],
]);
