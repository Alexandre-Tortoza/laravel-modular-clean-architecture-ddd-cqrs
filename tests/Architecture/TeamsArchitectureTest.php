<?php

use Tests\Architecture\Support\ModuleBoundaryInspector;

function inspectModules(): ModuleBoundaryInspector
{
    static $inspector;

    return $inspector ??= new ModuleBoundaryInspector(dirname(__DIR__, 2));
}

test('every module keeps domain and application independent of framework and adapters', function () {
    expect(inspectModules()->modules())->not->toBeEmpty();
    expect(inspectModules()->layerViolations())->toBe([]);
});

test('external consumers use only explicitly exported module symbols', function () {
    expect(inspectModules()->publicBoundaryViolations())->toBe([]);
});

test('module dependencies have no cycles', function () {
    expect(inspectModules()->cycleViolations())->toBe([]);
});

test('Identity APIs are used only by their intended modules', function () {
    expect(inspectModules()->restrictedApiViolations())->toBe([]);
});

test('domain and application do not call global framework helpers', function () {
    expect(inspectModules()->globalHelperViolations())->toBe([]);
});

test('commands and application data are readonly', function () {
    expect(inspectModules()->immutableValueViolations())->toBe([]);
});

test('each immutable command has one dedicated handler', function () {
    expect(inspectModules()->commandHandlerViolations())->toBe([]);
});

test('internal application ports are interfaces', function () {
    expect(inspectModules()->internalContractViolations())->toBe([]);
});

test('module Eloquent models and factories stay in infrastructure', function () {
    expect(inspectModules()->eloquentPlacementViolations())->toBe([]);
});

test('repositories do not control transactions and modules contain no debug helpers', function () {
    expect(inspectModules()->misplacedTransactionViolations())->toBe([]);
    expect(inspectModules()->debugHelperViolations())->toBe([]);
});

test('a new module with a forbidden dependency is discovered', function () {
    $root = sys_get_temp_dir().'/module-boundary-'.bin2hex(random_bytes(8));
    $module = $root.'/app/Modules/NewModule/Domain';
    $otherModule = $root.'/app/Modules/OtherModule/Infrastructure';
    mkdir($module, 0777, true);
    mkdir($otherModule, 0777, true);
    $file = $module.'/Forbidden.php';
    $otherFile = $otherModule.'/PrivateStore.php';
    file_put_contents($file, '<?php namespace App\\Modules\\NewModule\\Domain; use Illuminate\\Support\\Collection; use App\\Modules\\OtherModule\\Infrastructure\\PrivateStore; use App\\Modules\\Identity\\Application\\Api\\TeamSelectionAccounts; use App\\Modules\\Teams\\Application\\Api\\AccountTeamLifecycle; final class Forbidden { public function value(): Collection { app(); return new Collection; } }');
    file_put_contents($otherFile, '<?php namespace App\\Modules\\OtherModule\\Infrastructure; final class PrivateStore {}');

    try {
        $inspector = new ModuleBoundaryInspector($root);

        expect($inspector->modules())->toBe(['NewModule', 'OtherModule']);
        expect($inspector->layerViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php uses Illuminate\\Support\\Collection');
        expect($inspector->publicBoundaryViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php uses private App\\Modules\\OtherModule\\Infrastructure\\PrivateStore');
        expect($inspector->restrictedApiViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php uses Identity TeamSelectionAccounts outside its allowed modules');
        expect($inspector->restrictedApiViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php uses Teams AccountTeamLifecycle outside its allowed modules');
        expect($inspector->globalHelperViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php calls framework helper app');
    } finally {
        unlink($file);
        unlink($otherFile);
        rmdir($module);
        rmdir($otherModule);
        rmdir(dirname($module));
        rmdir(dirname($otherModule));
        rmdir(dirname($module, 2));
        rmdir(dirname($module, 3));
        rmdir($root);
    }
});

test('a mutable public API value is rejected in a newly discovered module', function () {
    $root = sys_get_temp_dir().'/module-api-'.bin2hex(random_bytes(8));
    $api = $root.'/app/Modules/NewModule/Application/Api';
    mkdir($api, 0777, true);
    $file = $api.'/MutableData.php';
    file_put_contents($file, '<?php namespace App\\Modules\\NewModule\\Application\\Api; class MutableData { public int $id = 1; }');

    try {
        expect((new ModuleBoundaryInspector($root))->immutableValueViolations())
            ->toContain('app/Modules/NewModule/Application/Api/MutableData.php must declare a readonly value class');
    } finally {
        unlink($file);
        rmdir($api);
        rmdir(dirname($api));
        rmdir(dirname($api, 2));
        rmdir(dirname($api, 3));
        rmdir(dirname($api, 4));
        rmdir($root);
    }
});

test('a module may use a pure Shared domain type but Shared may not depend on a module', function () {
    $root = sys_get_temp_dir().'/module-shared-'.bin2hex(random_bytes(8));
    $domain = $root.'/app/Modules/NewModule/Domain';
    $shared = $root.'/app/Shared/Domain';
    mkdir($domain, 0777, true);
    mkdir($shared, 0777, true);
    $moduleFile = $domain.'/UsesShared.php';
    $sharedFile = $shared.'/SharedValue.php';
    file_put_contents($moduleFile, '<?php namespace App\\Modules\\NewModule\\Domain; use App\\Shared\\Domain\\SharedValue; class UsesShared {}');
    file_put_contents($sharedFile, '<?php namespace App\\Shared\\Domain; readonly class SharedValue {}');

    try {
        expect((new ModuleBoundaryInspector($root))->layerViolations())->toBe([]);
        file_put_contents($sharedFile, '<?php namespace App\\Shared\\Domain; use App\\Modules\\NewModule\\Domain\\UsesShared; readonly class SharedValue {}');
        expect((new ModuleBoundaryInspector($root))->layerViolations())
            ->toContain('app/Shared/Domain/SharedValue.php uses App\\Modules\\NewModule\\Domain\\UsesShared');
        file_put_contents($sharedFile, '<?php namespace App\\Shared\\Domain; use Illuminate\\Support\\Collection; readonly class SharedValue {}');
        expect((new ModuleBoundaryInspector($root))->layerViolations())
            ->toContain('app/Shared/Domain/SharedValue.php uses Illuminate\\Support\\Collection');
        file_put_contents($sharedFile, '<?php namespace App\\Shared\\Domain; readonly class SharedValue { public static function make(): mixed { return app(); } }');
        expect((new ModuleBoundaryInspector($root))->globalHelperViolations())
            ->toContain('app/Shared/Domain/SharedValue.php calls framework helper app');
    } finally {
        unlink($moduleFile);
        unlink($sharedFile);
        rmdir($domain);
        rmdir($shared);
        rmdir(dirname($domain));
        rmdir(dirname($shared));
        rmdir(dirname($domain, 2));
        rmdir(dirname($shared, 2));
        rmdir($root);
    }
});
