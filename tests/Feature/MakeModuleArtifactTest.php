<?php

use App\Modules\Example\Infrastructure\Persistence\Eloquent\Factories\FactoryEntryFactory;
use App\Modules\Example\Infrastructure\Persistence\Eloquent\Factories\LinkedEntryFactory;
use App\Modules\Example\Infrastructure\Persistence\Eloquent\Models\FactoryEntry;
use App\Modules\Example\Infrastructure\Persistence\Eloquent\Models\LinkedEntry;
use App\Modules\Example\Infrastructure\Persistence\Eloquent\Models\PublishedEntry;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->artifactSandbox = sys_get_temp_dir().'/module-artifact-'.bin2hex(random_bytes(8));
    mkdir($this->artifactSandbox.'/app/Modules/Example', 0777, true);
    copy(base_path('composer.json'), $this->artifactSandbox.'/composer.json');
    $this->app->setBasePath($this->artifactSandbox);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->artifactSandbox);
});

test('module artifact delegates independent resources to native generators', function (string $type, string $name, string $path) {
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => $type, 'name' => $name])->assertSuccessful();
    $matches = (new Filesystem)->glob($this->artifactSandbox.'/app/Modules/Example/'.$path);
    expect($matches)->toHaveCount(1);
    expect((new Filesystem)->allFiles($this->artifactSandbox.'/app/Modules/Example'))->toHaveCount(1);
})->with([
    ['model', 'PlainEntry', 'Infrastructure/Persistence/Eloquent/Models/PlainEntry.php'],
    ['controller', 'EntryController', 'Presentation/Http/Controllers/EntryController.php'],
    ['request', 'SaveEntryRequest', 'Presentation/Http/Requests/SaveEntryRequest.php'],
    ['migration', 'create_artifact_entries_table', 'database/migrations/*_create_artifact_entries_table.php'],
]);

test('module model optionally wires its native factory through infrastructure newFactory', function () {
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'model', 'name' => 'FactoryEntry', '--factory' => true])->assertSuccessful();
    $eloquent = $this->artifactSandbox.'/app/Modules/Example/Infrastructure/Persistence/Eloquent';
    require $eloquent.'/Models/FactoryEntry.php';
    require $eloquent.'/Factories/FactoryEntryFactory.php';
    $factory = FactoryEntry::factory();
    expect($factory)->toBeInstanceOf(FactoryEntryFactory::class)
        ->and($factory->make())->toBeInstanceOf(FactoryEntry::class);
    expect((new Filesystem)->allFiles($this->artifactSandbox.'/app/Modules/Example'))->toHaveCount(2);
    expect(is_dir($this->artifactSandbox.'/database'))->toBeFalse();
});

test('module factory separately targets an existing infrastructure model without modifying it', function () {
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'model', 'name' => 'LinkedEntry'])->assertSuccessful();
    $model = $this->artifactSandbox.'/app/Modules/Example/Infrastructure/Persistence/Eloquent/Models/LinkedEntry.php';
    $original = file_get_contents($model);
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'factory', 'name' => 'LinkedEntry'])->assertSuccessful();
    $factoryPath = $this->artifactSandbox.'/app/Modules/Example/Infrastructure/Persistence/Eloquent/Factories/LinkedEntryFactory.php';
    require $model;
    require $factoryPath;
    expect(LinkedEntryFactory::new()->make())
        ->toBeInstanceOf(LinkedEntry::class);
    expect(file_get_contents($model))->toBe($original);
});

test('module artifacts reject unsupported inputs before generating files', function (string $module, string $type, string $name, bool $factory) {
    $this->artisan('make:module-artifact', ['module' => $module, 'type' => $type, 'name' => $name, '--factory' => $factory])->assertFailed();
    expect((new Filesystem)->allFiles($this->artifactSandbox.'/app'))->toBe([]);
})->with([
    ['../Outside', 'model', 'Entry', false],
    ['Example', 'model', '../Entry', false],
    ['Example', 'model', 'Int', false],
    ['Example', 'request', 'Class', false],
    ['Missing', 'controller', 'EntryController', false],
    ['EXAMPLE', 'model', 'Entry', false],
    ['Example', 'unknown', 'Entry', false],
    ['Example', 'request', 'SaveEntryRequest', true],
    ['Example', 'migration', '../create_entries_table', false],
    ['Example', 'factory', 'MissingEntry', false],
]);

test('module generators preserve all files on reexecution', function (string $type, string $name) {
    $arguments = ['module' => 'Example', 'type' => $type, 'name' => $name];
    $this->artisan('make:module-artifact', $arguments)->assertSuccessful();
    $paths = array_map(fn ($file) => $file->getPathname(), (new Filesystem)->allFiles($this->artifactSandbox.'/app/Modules/Example'));
    $contents = array_map(file_get_contents(...), $paths);
    $this->artisan('make:module-artifact', $arguments)->assertFailed();
    expect(array_map(file_get_contents(...), $paths))->toBe($contents);
    expect((new Filesystem)->allFiles($this->artifactSandbox.'/app/Modules/Example'))->toHaveCount(count($paths));
})->with([['model', 'UnchangedEntry'], ['migration', 'create_unchanged_entries_table']]);

test('module model preflights an existing factory before generating the model', function () {
    $path = $this->artifactSandbox.'/app/Modules/Example/Infrastructure/Persistence/Eloquent/Factories/CollisionEntryFactory.php';
    (new Filesystem)->ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'preserve user factory');
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'model', 'name' => 'CollisionEntry', '--factory' => true])->assertFailed();
    expect(file_get_contents($path))->toBe('preserve user factory');
    expect((new Filesystem)->allFiles($this->artifactSandbox.'/app/Modules/Example'))->toHaveCount(1);
});

test('module artifacts preserve published native stubs and unrelated existing resources', function () {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->artifactSandbox.'/stubs');
    $files->put($this->artifactSandbox.'/stubs/model.stub', <<<'STUB'
<?php
namespace {{ namespace }};
{{ factoryImport }}
use Illuminate\Database\Eloquent\Model;
class {{ class }} extends Model {
    {{ factory }}
    public function templateVersion(): string { return 'published'; }
}
STUB);
    $files->put($this->artifactSandbox.'/stubs/factory.stub', <<<'STUB'
<?php
namespace {{ factoryNamespace }};
use {{ namespacedModel }};
use Illuminate\Database\Eloquent\Factories\Factory;
/** @extends Factory<{{ model }}> */
final class {{ factory }}Factory extends Factory {
    /** @return array<string, mixed> */
    public function definition(): array { return []; }
}
STUB);
    $models = $this->artifactSandbox.'/app/Modules/Example/Infrastructure/Persistence/Eloquent/Models';
    $files->ensureDirectoryExists($models);
    $files->put($models.'/ExistingEntry.php', 'preserve unrelated resource');
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'model', 'name' => 'PublishedEntry', '--factory' => true])->assertSuccessful();
    require $models.'/PublishedEntry.php';
    require $this->artifactSandbox.'/app/Modules/Example/Infrastructure/Persistence/Eloquent/Factories/PublishedEntryFactory.php';
    $instance = PublishedEntry::factory()->make();
    expect($instance->templateVersion())->toBe('published');
    expect($files->get($models.'/ExistingEntry.php'))->toBe('preserve unrelated resource');
});

test('module migration preserves an unrelated native migration and rejects case collisions', function () {
    $files = new Filesystem;
    $migrations = $this->artifactSandbox.'/app/Modules/Example/database/migrations';
    $files->ensureDirectoryExists($migrations);
    $existing = <<<'MIGRATION'
<?php
return new class extends Illuminate\Database\Migrations\Migration {
    public function up(): void {}
    public function down(): void {}
};
MIGRATION;
    $files->put($migrations.'/2026_01_01_000000_create_other_entries_table.php', $existing);
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'migration', 'name' => 'create_new_entries_table'])->assertSuccessful();
    expect($files->get($migrations.'/2026_01_01_000000_create_other_entries_table.php'))->toBe($existing);
    $files->put($migrations.'/2026_01_02_000000_CREATE_CASE_ENTRIES_TABLE.php', $existing);
    $this->artisan('make:module-artifact', ['module' => 'Example', 'type' => 'migration', 'name' => 'create_case_entries_table'])->assertFailed();
    expect($files->allFiles($migrations))->toHaveCount(3);
});
