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
