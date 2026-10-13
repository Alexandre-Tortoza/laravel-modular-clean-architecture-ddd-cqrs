<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

test('independent template CI executes the disposable consumer smoke with read-only permissions', function () {
    $path = base_path('.github/workflows/template-smoke.yml');
    expect(is_file($path))->toBeTrue();
    $workflow = Yaml::parseFile($path);
    expect($workflow['on']['pull_request']['branches'])->toBe(['dev'])
        ->and($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and($workflow['on'])->not->toHaveKey('pull_request_target');
    $job = $workflow['jobs']['independent-consumer'];
    expect($job)->not->toHaveKey('continue-on-error');
    foreach ($job['steps'] as $step) {
        expect($step)->not->toHaveKey('continue-on-error');
        if (isset($step['uses'])) {
            expect($step['uses'])->toMatch('/@[a-f0-9]{40}\z/');
        }
    }
    $commands = implode("\n", array_column($job['steps'], 'run'));
    expect($commands)->toContain('bash .github/scripts/template-smoke.sh')
        ->and($commands)->not->toContain('gh repo create', 'terraform apply', 'docker push');
});

test('independent template smoke rejects a missing source checkout before installation', function () {
    $result = Process::path(base_path())->run(['bash', '.github/scripts/template-smoke.sh', '/missing-template-checkout']);
    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toBe("Provide a Git template checkout.\n");
});
