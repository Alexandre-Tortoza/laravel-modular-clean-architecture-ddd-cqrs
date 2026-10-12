<?php

use Tests\Architecture\Support\ModuleBoundaryInspector;

test('public module APIs and their exported data do not expose framework types', function () {
    $inspector = new ModuleBoundaryInspector(dirname(__DIR__, 2));

    expect($inspector->publicTypeViolations())->toBe([]);
});
