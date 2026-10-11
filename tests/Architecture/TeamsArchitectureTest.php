<?php

use Tests\Architecture\Support\ModuleBoundaryInspector;

function inspectModules(): ModuleBoundaryInspector
{
    return new ModuleBoundaryInspector(dirname(__DIR__, 2));
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

test('commands and application data are readonly', function () {
    expect(inspectModules()->immutableValueViolations())->toBe([]);
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
    file_put_contents($file, '<?php namespace App\\Modules\\NewModule\\Domain; use Illuminate\\Support\\Collection; use App\\Modules\\OtherModule\\Infrastructure\\PrivateStore; final class Forbidden { public function value(): Collection { return new Collection; } }');
    file_put_contents($otherFile, '<?php namespace App\\Modules\\OtherModule\\Infrastructure; final class PrivateStore {}');

    try {
        $inspector = new ModuleBoundaryInspector($root);

        expect($inspector->modules())->toBe(['NewModule', 'OtherModule']);
        expect($inspector->layerViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php uses Illuminate\\Support\\Collection');
        expect($inspector->publicBoundaryViolations())->toContain('app/Modules/NewModule/Domain/Forbidden.php uses private App\\Modules\\OtherModule\\Infrastructure\\PrivateStore');
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
