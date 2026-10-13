<?php

use App\Modules\Example\Infrastructure\Persistence\Seeders\DemoEntriesSeeder;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->seederSandbox = sys_get_temp_dir().'/module-seeder-'.bin2hex(random_bytes(8));
    mkdir($this->seederSandbox.'/app/Modules/Example', 0777, true);
    $this->rootSeederPath = base_path('database/seeders/DatabaseSeeder.php');
    $this->rootSeederContents = file_get_contents($this->rootSeederPath);
    $this->app->setBasePath($this->seederSandbox);
});

afterEach(function () {
    expect(file_get_contents($this->rootSeederPath))->toBe($this->rootSeederContents);
    (new Filesystem)->deleteDirectory($this->seederSandbox);
});

test('module seeder runs explicitly in local and testing without creating demo records', function () {
    $this->artisan('make:module-seeder', ['module' => 'Example', 'name' => 'DemoEntriesSeeder'])->assertSuccessful();
    require $this->seederSandbox.'/app/Modules/Example/Infrastructure/Persistence/Seeders/DemoEntriesSeeder.php';
    $class = DemoEntriesSeeder::class;
    foreach (['local', 'testing'] as $environment) {
        app()->instance('env', $environment);
        $this->artisan('db:seed', ['--class' => $class, '--force' => true])->assertSuccessful();
    }
    foreach (['production', 'staging'] as $environment) {
        app()->instance('env', $environment);
        expect(fn () => $this->artisan('db:seed', ['--class' => $class, '--force' => true])->run())
            ->toThrow(LogicException::class, 'Demo data may only be seeded in local or testing environments.');
    }
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
    expect(count((new Filesystem)->allFiles($this->seederSandbox)))->toBe(1);
});

test('module seeder rejects unsafe or invalid class names without writing files', function (string $module, string $name) {
    $this->artisan('make:module-seeder', ['module' => $module, 'name' => $name])->assertFailed();
    expect((new Filesystem)->allFiles($this->seederSandbox))->toBe([]);
})->with([
    ['../Outside', 'EntriesSeeder'],
    ['Example', 'Nested\\EntriesSeeder'],
    ['Example', 'Class'],
    ['Example', 'Int'],
    ['Example', 'Self'],
]);

test('module seeder requires an existing module with exact casing', function (string $module) {
    $this->artisan('make:module-seeder', ['module' => $module, 'name' => 'EntriesSeeder'])->assertFailed();
    expect((new Filesystem)->allFiles($this->seederSandbox))->toBe([]);
})->with(['Missing', 'EXAMPLE']);

test('module seeder preserves existing files and case collisions on reexecution', function (string $filename) {
    $path = $this->seederSandbox.'/app/Modules/Example/Infrastructure/Persistence/Seeders/'.$filename;
    (new Filesystem)->ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'preserve user behavior');
    $this->artisan('make:module-seeder', ['module' => 'Example', 'name' => 'EntriesSeeder'])->assertFailed();
    expect(file_get_contents($path))->toBe('preserve user behavior');
    expect(count((new Filesystem)->allFiles($this->seederSandbox)))->toBe(1);
})->with(['EntriesSeeder.php', 'ENTRIESSEEDER.php']);

test('module seeder can add an independent seeder and safely reject a generated duplicate', function () {
    $arguments = ['module' => 'Example', 'name' => 'FirstSeeder'];
    $this->artisan('make:module-seeder', $arguments)->assertSuccessful();
    $path = $this->seederSandbox.'/app/Modules/Example/Infrastructure/Persistence/Seeders/FirstSeeder.php';
    $contents = file_get_contents($path);
    $this->artisan('make:module-seeder', $arguments)->assertFailed();
    $this->artisan('make:module-seeder', ['module' => 'Example', 'name' => 'SecondSeeder'])->assertSuccessful();
    expect(file_get_contents($path))->toBe($contents);
    expect(count((new Filesystem)->allFiles($this->seederSandbox)))->toBe(2);
});
