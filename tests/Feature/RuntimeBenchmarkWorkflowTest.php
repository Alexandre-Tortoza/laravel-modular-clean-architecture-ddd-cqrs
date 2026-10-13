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

test('runtime benchmark computes percentiles and throughput from actual sample durations', function () {
    $result = Process::path(base_path())->env(['PYTHONDONTWRITEBYTECODE' => '1'])->run(['python', '-c', <<<'PYTHON'
import importlib.util,json
spec=importlib.util.spec_from_file_location('benchmark','tests/Docker/runtime-benchmark-client.py')
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
result=module.latency_summary(list(range(1,101)),2)
print(json.dumps({key:result[key] for key in ['requests','requests_per_second','p50_ms','p95_ms','mean_ms']}))
PYTHON]);
    expect($result->exitCode())->toBe(0)
        ->and(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'requests' => 100,
            'requests_per_second' => 50.0,
            'p50_ms' => 50,
            'p95_ms' => 95,
            'mean_ms' => 50.5,
        ]);
});
