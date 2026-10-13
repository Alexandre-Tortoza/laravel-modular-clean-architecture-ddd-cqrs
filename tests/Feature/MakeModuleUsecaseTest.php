<?php

use App\Modules\Example\Application\Commands\ArchiveEntries;
use App\Modules\Example\Application\Commands\ArchiveEntriesHandler;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->usecaseSandbox = sys_get_temp_dir().'/module-usecase-'.bin2hex(random_bytes(8));
    mkdir($this->usecaseSandbox.'/app/Modules/Example', 0777, true);
    $this->app->setBasePath($this->usecaseSandbox);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->usecaseSandbox);
});

test('module usecase creates a readonly command and a handler that fails before business behavior is implemented', function () {
    $this->artisan('make:module-usecase', ['module' => 'Example', 'name' => 'ArchiveEntries'])->assertSuccessful();
    $commands = $this->usecaseSandbox.'/app/Modules/Example/Application/Commands';
    require $commands.'/ArchiveEntries.php';
    require $commands.'/ArchiveEntriesHandler.php';
    expect((new ReflectionClass(ArchiveEntries::class))->isReadOnly())->toBeTrue();
    $handler = new ArchiveEntriesHandler;
    expect(fn () => $handler->handle(new ArchiveEntries))
        ->toThrow(LogicException::class, 'Implement ArchiveEntries before executing it.');
    expect(array_map(fn ($file) => $file->getRelativePathname(), (new Filesystem)->allFiles($this->usecaseSandbox.'/app/Modules/Example')))
        ->toBe(['Application/Commands/ArchiveEntries.php', 'Application/Commands/ArchiveEntriesHandler.php']);
    expect(is_dir($this->usecaseSandbox.'/tests'))->toBeFalse();
});

test('module usecase creates a pending Pest test only when explicitly requested', function () {
    $this->artisan('make:module-usecase', ['module' => 'Example', 'name' => 'PublishEntries', '--test' => true])->assertSuccessful();
    $test = $this->usecaseSandbox.'/tests/Unit/Modules/Example/PublishEntriesTest.php';
    expect(file_exists($test))->toBeTrue();
    expect(file_get_contents($test))->toContain('->todo();');
});

test('module usecase rejects invalid names before creating files', function (string $module, string $name) {
    $this->artisan('make:module-usecase', ['module' => $module, 'name' => $name])->assertFailed();
    expect((new Filesystem)->allFiles($this->usecaseSandbox))->toBe([]);
})->with([
    ['../Outside', 'ArchiveEntries'],
    ['Example', '../Outside'],
    ['Example', 'Nested\\Name'],
    ['example', 'ArchiveEntries'],
    ['Example', 'Invalid-Name'],
    ['Example', 'Class'],
]);

test('module usecase requires an existing module with the requested casing', function (string $module) {
    $this->artisan('make:module-usecase', ['module' => $module, 'name' => 'ArchiveEntries'])->assertFailed();
    expect((new Filesystem)->allFiles($this->usecaseSandbox))->toBe([]);
})->with(['Missing', 'EXAMPLE']);

test('module usecase refuses existing targets before writing any other file', function (string $existing) {
    $path = $this->usecaseSandbox.'/'.$existing;
    (new Filesystem)->ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'preserve user content');
    $this->artisan('make:module-usecase', ['module' => 'Example', 'name' => 'UpdateEntries', '--test' => true])->assertFailed();
    expect(file_get_contents($path))->toBe('preserve user content');
    expect(count((new Filesystem)->allFiles($this->usecaseSandbox)))->toBe(1);
})->with([
    'app/Modules/Example/Application/Commands/UpdateEntries.php',
    'app/Modules/Example/Application/Commands/UpdateEntriesHandler.php',
    'tests/Unit/Modules/Example/UpdateEntriesTest.php',
    'app/Modules/Example/Application/Commands/UPDATEENTRIES.php',
]);

test('module usecase reexecution preserves every generated file', function () {
    $arguments = ['module' => 'Example', 'name' => 'CloseEntries', '--test' => true];
    $this->artisan('make:module-usecase', $arguments)->assertSuccessful();
    $paths = array_map(fn ($file) => $file->getPathname(), (new Filesystem)->allFiles($this->usecaseSandbox));
    $contents = array_map(file_get_contents(...), $paths);
    $this->artisan('make:module-usecase', $arguments)->assertFailed();
    expect(array_map(file_get_contents(...), $paths))->toBe($contents);
});
