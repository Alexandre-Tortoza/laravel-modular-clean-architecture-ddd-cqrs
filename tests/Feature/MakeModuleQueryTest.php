<?php

use App\Modules\Example\Application\Contracts\FindEntryQuery;
use App\Modules\Example\Application\Data\LoadSummaryData;
use App\Modules\Example\Application\Queries\LoadSummary;
use App\Modules\Example\Infrastructure\Queries\FindEntry;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->querySandbox = sys_get_temp_dir().'/module-query-'.bin2hex(random_bytes(8));
    mkdir($this->querySandbox.'/app/Modules/Example', 0777, true);
    $this->app->setBasePath($this->querySandbox);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->querySandbox);
});

test('module query creates only a concrete service and an immutable output until behavior is implemented', function () {
    $this->artisan('make:module-query', ['module' => 'Example', 'name' => 'LoadSummary'])->assertSuccessful();
    $module = $this->querySandbox.'/app/Modules/Example';
    require $module.'/Application/Data/LoadSummaryData.php';
    require $module.'/Application/Queries/LoadSummary.php';

    expect((new ReflectionClass(LoadSummaryData::class))->isReadOnly())->toBeTrue();
    expect((new ReflectionMethod(LoadSummary::class, 'handle'))->getReturnType()->getName())->toBe(LoadSummaryData::class);
    expect(fn () => (new LoadSummary)->handle())->toThrow(LogicException::class, 'Implement LoadSummary before querying it.');
    expect(array_map(fn ($file) => $file->getRelativePathname(), (new Filesystem)->allFiles($module)))
        ->toBe(['Application/Data/LoadSummaryData.php', 'Application/Queries/LoadSummary.php']);
    expect(is_dir($this->querySandbox.'/tests'))->toBeFalse();
});

test('module query generates an application contract and an infrastructure adapter only when requested', function () {
    $this->artisan('make:module-query', ['module' => 'Example', 'name' => 'FindEntry', '--contract' => true])->assertSuccessful();
    $module = $this->querySandbox.'/app/Modules/Example';
    require $module.'/Application/Data/FindEntryData.php';
    require $module.'/Application/Contracts/FindEntryQuery.php';
    require $module.'/Infrastructure/Queries/FindEntry.php';

    expect(new FindEntry)->toBeInstanceOf(FindEntryQuery::class);
    expect(fn () => (new FindEntry)->handle())->toThrow(LogicException::class, 'Implement FindEntry before querying it.');
    expect(array_map(fn ($file) => $file->getRelativePathname(), (new Filesystem)->allFiles($module)))
        ->toBe(['Application/Contracts/FindEntryQuery.php', 'Application/Data/FindEntryData.php', 'Infrastructure/Queries/FindEntry.php']);
});

test('module query creates a pending Pest test only when explicitly requested', function () {
    $this->artisan('make:module-query', ['module' => 'Example', 'name' => 'ReadNotes', '--test' => true])->assertSuccessful();

    $test = $this->querySandbox.'/tests/Unit/Modules/Example/ReadNotesTest.php';
    expect(file_exists($test))->toBeTrue();
    expect(file_get_contents($test))->toContain('->todo();');
});

test('module query can coexist with unrelated queries and projections without changing them', function () {
    $module = $this->querySandbox.'/app/Modules/Example';
    $files = new Filesystem;
    $files->ensureDirectoryExists($module.'/Application/Data');
    $files->ensureDirectoryExists($module.'/Application/Queries');
    $files->put($module.'/Application/Data/ExistingSummaryData.php', 'existing projection content');
    $files->put($module.'/Application/Queries/ExistingSummary.php', 'existing query content');

    $this->artisan('make:module-query', ['module' => 'Example', 'name' => 'ReadStatistics'])->assertSuccessful();

    expect($files->get($module.'/Application/Data/ExistingSummaryData.php'))->toBe('existing projection content');
    expect($files->get($module.'/Application/Queries/ExistingSummary.php'))->toBe('existing query content');
    expect($files->exists($module.'/Application/Data/ReadStatisticsData.php'))->toBeTrue();
    expect($files->exists($module.'/Application/Queries/ReadStatistics.php'))->toBeTrue();
});

test('module query rejects invalid names before creating files', function (string $module, string $name) {
    $this->artisan('make:module-query', ['module' => $module, 'name' => $name])->assertFailed();

    expect((new Filesystem)->allFiles($this->querySandbox))->toBe([]);
})->with([
    ['../Outside', 'LoadSummary'],
    ['Example', '../Outside'],
    ['Example', 'Nested\\Name'],
    ['example', 'LoadSummary'],
    ['Example', 'Invalid-Name'],
    ['Example', 'Class'],
    ['Example', 'Int'],
    ['Example', 'Self'],
]);

test('module query requires an existing module with the requested casing', function (string $module) {
    $this->artisan('make:module-query', ['module' => $module, 'name' => 'LoadSummary'])->assertFailed();

    expect((new Filesystem)->allFiles($this->querySandbox))->toBe([]);
})->with(['Missing', 'EXAMPLE']);

test('module query refuses existing targets before writing any other file', function (string $existing, bool $contract) {
    $path = $this->querySandbox.'/'.$existing;
    (new Filesystem)->ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'preserve user content');

    $this->artisan('make:module-query', ['module' => 'Example', 'name' => 'ReadEntry', '--contract' => $contract, '--test' => true])->assertFailed();

    expect(file_get_contents($path))->toBe('preserve user content');
    expect(count((new Filesystem)->allFiles($this->querySandbox)))->toBe(1);
})->with([
    ['app/Modules/Example/Application/Data/ReadEntryData.php', false],
    ['app/Modules/Example/Application/Queries/ReadEntry.php', false],
    ['app/Modules/Example/Application/Queries/READENTRY.php', false],
    ['app/Modules/Example/Application/Contracts/ReadEntryQuery.php', true],
    ['app/Modules/Example/Infrastructure/Queries/ReadEntry.php', true],
    ['tests/Unit/Modules/Example/ReadEntryTest.php', true],
]);

test('module query reexecution preserves every generated file', function () {
    $arguments = ['module' => 'Example', 'name' => 'ListEntries', '--contract' => true, '--test' => true];
    $this->artisan('make:module-query', $arguments)->assertSuccessful();
    $paths = array_map(fn ($file) => $file->getPathname(), (new Filesystem)->allFiles($this->querySandbox));
    $contents = array_map(file_get_contents(...), $paths);

    $this->artisan('make:module-query', $arguments)->assertFailed();

    expect(array_map(file_get_contents(...), $paths))->toBe($contents);
});
