<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Tests\Architecture\Support\ModuleBoundaryInspector;

test('a generated module passes native boot, PSR-4, migration and architecture checks in an isolated checkout', function () {
    $files = new Filesystem;
    $root = base_path();
    $fixture = sys_get_temp_dir().'/module-scaffolding-'.bin2hex(random_bytes(8));
    $originalProviders = $files->get($root.'/bootstrap/providers.php');
    $originalModules = $files->directories($root.'/app/Modules');

    try {
        foreach (['app', 'config', 'routes', 'database/migrations', 'database/seeders'] as $directory) {
            $files->copyDirectory($root.'/'.$directory, $fixture.'/'.$directory);
        }
        $files->copy($root.'/composer.json', $fixture.'/composer.json');
        $files->ensureDirectoryExists($fixture.'/bootstrap/cache');
        $files->copy($root.'/bootstrap/app.php', $fixture.'/bootstrap/app.php');
        $files->copy($root.'/bootstrap/providers.php', $fixture.'/bootstrap/providers.php');
        $files->ensureDirectoryExists($fixture.'/storage/framework/cache');
        $files->ensureDirectoryExists($fixture.'/storage/logs');
        $files->put($fixture.'/database/scaffolding.sqlite', '');
        $files->put($fixture.'/artisan', str_replace('{{ autoload }}', var_export($root.'/vendor/autoload.php', true), <<<'LAUNCHER'
<?php
require {{ autoload }};
$loader = new Composer\Autoload\ClassLoader;
$loader->addPsr4('App\\', __DIR__.'/app');
$loader->register(true);
$app = require __DIR__.'/bootstrap/app.php';
exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
LAUNCHER));
        $environment = [
            'COMPOSER_VENDOR_DIR' => $root.'/vendor',
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $fixture.'/database/scaffolding.sqlite',
            'DB_URL' => '',
            'XDEBUG_MODE' => 'off',
        ];
        $run = fn (array $command) => Process::path($fixture)->env($environment)->timeout(120)->run($command);
        $generated = $run([PHP_BINARY, 'artisan', 'make:module', 'InventoryKit', '--no-interaction']);
        expect($generated->exitCode())->toBe(0, $generated->output().$generated->errorOutput());
        $module = $fixture.'/app/Modules/InventoryKit';
        foreach ([['model', 'InventoryEntry', '--factory'], ['controller', 'InventoryEntryController'], ['request', 'StoreInventoryEntryRequest'], ['migration', 'create_inventory_entries_table']] as $artifact) {
            $resource = $run([PHP_BINARY, 'artisan', 'make:module-artifact', 'InventoryKit', ...$artifact, '--no-interaction']);
            expect($resource->exitCode())->toBe(0, $resource->output().$resource->errorOutput());
        }
        $usecase = $run([PHP_BINARY, 'artisan', 'make:module-usecase', 'InventoryKit', 'ArchiveEntries', '--test', '--no-interaction']);
        expect($usecase->exitCode())->toBe(0, $usecase->output().$usecase->errorOutput());
        foreach ([['InventorySummary'], ['EntryLookup', '--contract']] as $queryArguments) {
            $query = $run([PHP_BINARY, 'artisan', 'make:module-query', 'InventoryKit', ...$queryArguments, '--no-interaction']);
            expect($query->exitCode())->toBe(0, $query->output().$query->errorOutput());
        }
        $files->put($fixture.'/usecase.php', str_replace('{{ autoload }}', var_export($root.'/vendor/autoload.php', true), <<<'USECASE'
<?php
require {{ autoload }};
$loader = new Composer\Autoload\ClassLoader;
$loader->addPsr4('App\\', __DIR__.'/app');
$loader->register(true);
$command = new App\Modules\InventoryKit\Application\Commands\ArchiveEntries;
if (!(new ReflectionClass($command))->isReadOnly()) {
    exit(1);
}
$handler = new App\Modules\InventoryKit\Application\Commands\ArchiveEntriesHandler;
try {
    $handler->handle($command);
    exit(2);
} catch (LogicException $exception) {
    echo $exception->getMessage();
}
USECASE));
        $autoload = $run([PHP_BINARY, 'usecase.php']);
        expect($autoload->exitCode())->toBe(0, $autoload->output().$autoload->errorOutput())
            ->and($autoload->output())->toBe('Implement ArchiveEntries before executing it.');
        $files->put($fixture.'/queries.php', str_replace('{{ autoload }}', var_export($root.'/vendor/autoload.php', true), <<<'QUERIES'
<?php
require {{ autoload }};
$loader = new Composer\Autoload\ClassLoader;
$loader->addPsr4('App\\', __DIR__.'/app');
$loader->register(true);
foreach ([App\Modules\InventoryKit\Application\Data\InventorySummaryData::class, App\Modules\InventoryKit\Application\Data\EntryLookupData::class] as $projection) {
    if (!(new ReflectionClass($projection))->isReadOnly()) {
        exit(1);
    }
}
$direct = new App\Modules\InventoryKit\Application\Queries\InventorySummary;
$adapter = new App\Modules\InventoryKit\Infrastructure\Queries\EntryLookup;
if (!$adapter instanceof App\Modules\InventoryKit\Application\Contracts\EntryLookupQuery) {
    exit(2);
}
foreach ([$direct, $adapter] as $query) {
    try {
        $query->handle();
        exit(3);
    } catch (LogicException $exception) {
        echo $exception->getMessage().PHP_EOL;
    }
}
QUERIES));
        $queries = $run([PHP_BINARY, 'queries.php']);
        expect($queries->exitCode())->toBe(0, $queries->output().$queries->errorOutput());
        expect($queries->output())->toBe("Implement InventorySummary before querying it.\nImplement EntryLookup before querying it.\n");
        $files->put($fixture.'/phpunit.xml', '<phpunit bootstrap="'.$root.'/vendor/autoload.php"><testsuites><testsuite name="Unit"><directory>tests/Unit</directory></testsuite></testsuites></phpunit>');
        $pendingTest = Process::path($fixture)->env([...$environment, 'PAO_FORCE' => 'true'])->timeout(120)
            ->run([PHP_BINARY, $root.'/vendor/bin/pest', '--configuration='.$fixture.'/phpunit.xml', '--no-coverage', '--colors=never', $fixture.'/tests/Unit/Modules/InventoryKit/ArchiveEntriesTest.php']);
        expect($pendingTest->exitCode())->toBe(0, $pendingTest->output().$pendingTest->errorOutput());
        $pendingResult = json_decode($pendingTest->output(), true, flags: JSON_THROW_ON_ERROR);
        expect($pendingResult['tests'])->toBe(1)
            ->and($pendingResult['passed'])->toBe(0)
            ->and($pendingResult['skipped'])->toBe(1);
        $console = $run([PHP_BINARY, 'artisan', 'make:module-command', 'InventoryKit', 'ArchiveEntries', '--signature=inventory-kit:archive-entries {input}', '--no-interaction']);
        expect($console->exitCode())->toBe(0, $console->output().$console->errorOutput());
        $listed = $run([PHP_BINARY, 'artisan', 'list', '--format=json']);
        expect($listed->exitCode())->toBe(0, $listed->output().$listed->errorOutput());
        $commands = json_decode($listed->output(), true, flags: JSON_THROW_ON_ERROR)['commands'];
        expect(count(array_filter($commands, fn (array $command): bool => $command['name'] === 'inventory-kit:archive-entries')))->toBe(1);
        $unimplemented = $run([PHP_BINARY, 'artisan', 'inventory-kit:archive-entries', 'example', '--no-interaction']);
        expect($unimplemented->exitCode())->toBe(1)
            ->and($unimplemented->output().$unimplemented->errorOutput())->toContain('Implement delegation to the module Application layer');
        $consolePath = $module.'/Presentation/Console/ArchiveEntries.php';
        $consoleSource = $files->get($consolePath);
        $files->put($consolePath, str_replace([
            'public function handle(): int',
            "throw new LogicException('Implement delegation to the module Application layer before executing this command.');",
        ], [
            'public function handle(\\App\\Modules\\InventoryKit\\Application\\Commands\\ArchiveEntriesHandler $handler): int',
            '$handler->handle(new \\App\\Modules\\InventoryKit\\Application\\Commands\\ArchiveEntries); return self::SUCCESS;',
        ], $consoleSource));
        $delegated = $run([PHP_BINARY, 'artisan', 'inventory-kit:archive-entries', 'example', '--no-interaction']);
        expect($delegated->exitCode())->toBe(1)
            ->and($delegated->output().$delegated->errorOutput())->toContain('Implement ArchiveEntries before executing it.');
        $files->put($consolePath, $consoleSource);
        $consoleRerun = $run([PHP_BINARY, 'artisan', 'make:module-command', 'InventoryKit', 'ArchiveEntries', '--no-interaction']);
        expect($consoleRerun->exitCode())->toBe(1)
            ->and($files->get($consolePath))->toBe($consoleSource);
        $fixtureDatabaseSeeder = $files->get($fixture.'/database/seeders/DatabaseSeeder.php');
        $generatedSeeder = $run([PHP_BINARY, 'artisan', 'make:module-seeder', 'InventoryKit', 'DemoEntriesSeeder', '--no-interaction']);
        expect($generatedSeeder->exitCode())->toBe(0, $generatedSeeder->output().$generatedSeeder->errorOutput());
        $seederClass = 'App\\Modules\\InventoryKit\\Infrastructure\\Persistence\\Seeders\\DemoEntriesSeeder';
        foreach (['local', 'testing', 'production', 'staging'] as $environmentName) {
            $seeded = Process::path($fixture)->env([...$environment, 'APP_ENV' => $environmentName])->timeout(120)
                ->run([PHP_BINARY, 'artisan', 'db:seed', '--class='.$seederClass, '--force', '--no-interaction']);
            $isAllowed = in_array($environmentName, ['local', 'testing'], true);
            expect($seeded->exitCode())->toBe($isAllowed ? 0 : 1, $seeded->output().$seeded->errorOutput());
            if (! $isAllowed) {
                expect($seeded->output().$seeded->errorOutput())->toContain('Demo data may only be seeded in local or testing environments.');
            }
        }
        expect($files->get($fixture.'/database/seeders/DatabaseSeeder.php'))->toBe($fixtureDatabaseSeeder);
        $seederPath = $module.'/Infrastructure/Persistence/Seeders/DemoEntriesSeeder.php';
        $seederContents = $files->get($seederPath);
        $seederRerun = $run([PHP_BINARY, 'artisan', 'make:module-seeder', 'InventoryKit', 'DemoEntriesSeeder', '--no-interaction']);
        expect($seederRerun->exitCode())->toBe(1)
            ->and($files->get($seederPath))->toBe($seederContents);
        $files->put($module.'/routes/web.php', <<<'ROUTES'
<?php
use Illuminate\Support\Facades\Route;
Route::middleware('web')->get('/inventory-kit', fn (): string => 'inventory')->name('inventory-kit.index');
ROUTES);
        $files->put($module.'/database/migrations/2026_01_01_000000_create_inventory_kit_entries.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('inventory_kit_entries', function (Blueprint $table): void { $table->id(); }); }
    public function down(): void { Schema::dropIfExists('inventory_kit_entries'); }
};
MIGRATION);
        $routes = $run([PHP_BINARY, 'artisan', 'route:list', '--path=inventory-kit', '--json']);
        expect($routes->exitCode())->toBe(0, $routes->output().$routes->errorOutput());
        $route = json_decode($routes->output(), true, flags: JSON_THROW_ON_ERROR)[0];
        expect($route['name'])->toBe('inventory-kit.index')
            ->and($route['middleware'])->toContain('web');
        foreach ([['migrate', '--no-interaction'], ['migrate:status', '--no-interaction']] as $command) {
            $result = $run([PHP_BINARY, 'artisan', ...$command]);
            expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
            expect($result->output())->toContain('2026_01_01_000000_create_inventory_kit_entries');
        }
        $files->put($fixture.'/artifacts.php', str_replace('{{ autoload }}', var_export($root.'/vendor/autoload.php', true), <<<'ARTIFACTS'
<?php
require {{ autoload }};
$loader = new Composer\Autoload\ClassLoader;
$loader->addPsr4('App\\', __DIR__.'/app');
$loader->register(true);
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$factory = App\Modules\InventoryKit\Infrastructure\Persistence\Eloquent\Models\InventoryEntry::factory();
$entry = $factory->create();
$controller = new App\Modules\InventoryKit\Presentation\Http\Controllers\InventoryEntryController;
$request = new App\Modules\InventoryKit\Presentation\Http\Requests\StoreInventoryEntryRequest;
echo json_encode([
    'factory' => get_class($factory),
    'model' => get_class($entry),
    'count' => $entry->newQuery()->count(),
    'controller' => $controller instanceof App\Http\Controllers\Controller,
    'request' => $request instanceof Illuminate\Foundation\Http\FormRequest,
], JSON_THROW_ON_ERROR);
ARTIFACTS));
        $artifacts = $run([PHP_BINARY, 'artifacts.php']);
        expect($artifacts->exitCode())->toBe(0, $artifacts->output().$artifacts->errorOutput());
        $artifactResult = json_decode($artifacts->output(), true, flags: JSON_THROW_ON_ERROR);
        expect($artifactResult)->toBe([
            'factory' => 'App\\Modules\\InventoryKit\\Infrastructure\\Persistence\\Eloquent\\Factories\\InventoryEntryFactory',
            'model' => 'App\\Modules\\InventoryKit\\Infrastructure\\Persistence\\Eloquent\\Models\\InventoryEntry',
            'count' => 1,
            'controller' => true,
            'request' => true,
        ]);
        $database = new PDO('sqlite:'.$fixture.'/database/scaffolding.sqlite');
        expect($database->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'inventory_kit_entries'")->fetchColumn())->toBe(1);
        $generatedFiles = array_map(fn ($file): string => $file->getPathname(), $files->allFiles($module, true));
        $checksums = array_map(fn (string $path): string => hash_file('sha256', $path), $generatedFiles);
        $fixtureProviders = $files->get($fixture.'/bootstrap/providers.php');
        $rerun = $run([PHP_BINARY, 'artisan', 'make:module', 'InventoryKit', '--no-interaction']);
        expect($rerun->exitCode())->toBe(1);
        expect(array_map(fn (string $path): string => hash_file('sha256', $path), $generatedFiles))->toBe($checksums)
            ->and($files->get($fixture.'/bootstrap/providers.php'))->toBe($fixtureProviders);
        $invalid = $run([PHP_BINARY, 'artisan', 'make:module', '../Escape', '--no-interaction']);
        expect($invalid->exitCode())->toBe(1)
            ->and(is_dir($fixture.'/app/Escape'))->toBeFalse();
        $analysis = $run([PHP_BINARY, $root.'/vendor/bin/phpstan', 'analyse', '--configuration='.$root.'/phpstan.neon', '--no-progress', '--error-format=json', '--memory-limit=1G', $module]);
        expect($analysis->exitCode())->toBe(0, $analysis->output().$analysis->errorOutput());
        $inspector = new ModuleBoundaryInspector($fixture);
        expect($inspector->modules())->toContain('InventoryKit');
        foreach (['layerViolations', 'publicBoundaryViolations', 'cycleViolations', 'restrictedApiViolations', 'globalHelperViolations', 'immutableValueViolations', 'commandHandlerViolations', 'internalContractViolations', 'eloquentPlacementViolations', 'misplacedTransactionViolations', 'debugHelperViolations', 'publicTypeViolations'] as $check) {
            expect($inspector->$check())->toBe([], $check);
        }
    } finally {
        $files->deleteDirectory($fixture);
    }

    expect($files->get($root.'/bootstrap/providers.php'))->toBe($originalProviders)
        ->and($files->directories($root.'/app/Modules'))->toBe($originalModules)
        ->and(is_dir($fixture))->toBeFalse();
});
