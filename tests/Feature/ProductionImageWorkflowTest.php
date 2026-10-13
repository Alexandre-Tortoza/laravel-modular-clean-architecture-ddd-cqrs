<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

test('production image CI evaluates every dev pull request with restricted credentials', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/production-smoke.yml'));
    expect($workflow['on']['pull_request']['branches'])->toBe(['dev'])
        ->and($workflow['on']['pull_request'])->not->toHaveKey('paths')
        ->and($workflow['on'])->not->toHaveKey('pull_request_target')
        ->and($workflow['permissions'])->toBe(['contents' => 'read']);
    foreach ($workflow['jobs'] as $job) {
        expect($job['permissions'] ?? $workflow['permissions'])->toBe(['contents' => 'read']);
        expect($job)->not->toHaveKey('continue-on-error');
        foreach ($job['steps'] as $step) {
            expect($step)->not->toHaveKey('continue-on-error');
            if (isset($step['uses'])) {
                expect($step['uses'])->toMatch('/@[a-f0-9]{40}\z/');
                if (str_starts_with($step['uses'], 'actions/checkout@')) {
                    expect($step['with']['persist-credentials'])->toBeFalse();
                }
            }
        }
    }
    $source = file_get_contents(base_path('.github/workflows/production-smoke.yml'));
    expect($source)->not->toMatch('/secrets\.|id-token:|packages:|pull_request_target|docker (push|login)|terraform (apply|destroy)|aws-actions/');
});

test('production image CI builds both final images and runs PostgreSQL with optional Redis', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/production-smoke.yml'));
    $job = $workflow['jobs']['production-postgresql'];
    expect($job['strategy']['matrix']['store'] ?? [])->toBe(['database', 'redis'])
        ->and($job['strategy']['fail-fast'] ?? true)->toBeFalse();
    $commands = implode("\n", array_column($job['steps'], 'run'));
    expect($commands)->toContain('docker build', '--target runtime', '--target nginx', 'SOURCE_REVISION=', 'tests/Docker/production-smoke.sh', '${{ matrix.store }}');
    expect($commands)->not->toContain('|| true', 'continue-on-error');
});

test('the production smoke keeps the existing quality and exact coverage gates', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/tests.yml'));
    expect(array_keys($workflow['jobs']))->toContain('ci', 'minimum-php', 'postgres-concurrency', 'sail', 'coverage');
    $ciCommands = implode("\n", array_column($workflow['jobs']['ci']['steps'], 'run'));
    $coverageCommands = implode("\n", array_column($workflow['jobs']['coverage']['steps'], 'run'));
    expect($ciCommands)->toContain('composer ci:check')
        ->and($coverageCommands)->toContain('composer coverage')
        ->and($workflow['jobs']['coverage']['if'])->toBe("github.event_name == 'workflow_dispatch'");
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($composer['scripts']['coverage'][0])->toContain('--exactly=100', '--branch-coverage')
        ->and($composer['scripts']['coverage'][1])->toContain('AssertFullCoverage.php');
});

test('the production smoke Redis service is opt in and stays on its private network', function () {
    $compose = Yaml::parseFile(base_path('compose.production.smoke.yaml'));
    expect($compose['services']['pgsql']['image'])->toStartWith('postgres:16.');
    $redis = $compose['services']['redis'];
    expect($redis['profiles'])->toBe(['redis'])
        ->and($redis['networks'])->toBe(['database'])
        ->and($redis)->not->toHaveKey('ports')
        ->and($redis)->not->toHaveKey('volumes')
        ->and($redis['user'])->toBe('redis')
        ->and($redis['read_only'])->toBeTrue()
        ->and($compose['networks']['database']['internal'])->toBeTrue();
});

test('an unsupported production smoke store fails before allocating Docker resources', function () {
    $result = Process::path(base_path())->run(['bash', 'tests/Docker/production-smoke.sh', 'php:probe', 'nginx:probe', str_repeat('a', 40), 'filesystem']);
    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toBe("Choose the database or redis smoke store.\n");
});
