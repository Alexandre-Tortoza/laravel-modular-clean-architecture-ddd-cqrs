<?php

use App\Modules\Billing\Infrastructure\Providers\BillingServiceProvider;
use App\Modules\Catalog\Infrastructure\Providers\CatalogServiceProvider;
use App\Modules\Shipping\Infrastructure\Providers\ShippingServiceProvider;
use App\Providers\AppServiceProvider;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->moduleSandbox = sys_get_temp_dir().'/make-module-'.bin2hex(random_bytes(8));
    mkdir($this->moduleSandbox.'/app', 0777, true);
    mkdir($this->moduleSandbox.'/bootstrap');
    $this->app->useAppPath($this->moduleSandbox.'/app');
    $this->app->useBootstrapPath($this->moduleSandbox.'/bootstrap');
    $this->providerContents = <<<'PROVIDERS'
<?php

use App\Providers\AppServiceProvider;

// Preserve this composition order.
return [
    AppServiceProvider::class,
];
// Preserve trailing instructions too.
PROVIDERS;
    file_put_contents($this->moduleSandbox.'/bootstrap/providers.php', $this->providerContents);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->moduleSandbox);
});

test('make module creates minimal PSR-4 scaffolding and appends its provider without rewriting composition', function () {
    $this->artisan('make:module', ['name' => 'Billing'])->assertSuccessful();
    $module = $this->moduleSandbox.'/app/Modules/Billing';
    $provider = $module.'/Infrastructure/Providers/BillingServiceProvider.php';
    require $provider;
    expect(class_exists(BillingServiceProvider::class))->toBeTrue();
    expect(file_exists($module.'/routes/web.php'))->toBeTrue()
        ->and(is_dir($module.'/database/migrations'))->toBeTrue();
    $files = (new Filesystem)->allFiles($module, true);
    expect(array_map(fn ($file) => $file->getRelativePathname(), $files))
        ->toBe(['Infrastructure/Providers/BillingServiceProvider.php', 'database/migrations/.gitkeep', 'routes/web.php']);
    expect(file_get_contents($this->moduleSandbox.'/bootstrap/providers.php'))
        ->toBe(str_replace('];', '    App\\Modules\\Billing\\Infrastructure\\Providers\\BillingServiceProvider::class,'."\n];", $this->providerContents));
});

test('make module fails on reexecution and preserves existing files and registration', function () {
    $this->artisan('make:module', ['name' => 'Inventory'])->assertSuccessful();
    $route = $this->moduleSandbox.'/app/Modules/Inventory/routes/web.php';
    file_put_contents($route, '<?php // user changes');
    $providers = file_get_contents($this->moduleSandbox.'/bootstrap/providers.php');
    $this->artisan('make:module', ['name' => 'Inventory'])->assertFailed();
    expect(file_get_contents($route))->toBe('<?php // user changes')
        ->and(file_get_contents($this->moduleSandbox.'/bootstrap/providers.php'))->toBe($providers);
});

test('make module rejects unsafe or ambiguous names before writing files', function (string $name) {
    $this->artisan('make:module', ['name' => $name])->assertFailed();
    expect(is_dir($this->moduleSandbox.'/app/Modules'))->toBeFalse()
        ->and(file_get_contents($this->moduleSandbox.'/bootstrap/providers.php'))->toBe($this->providerContents);
})->with(['../Outside', 'Foo/Bar', 'Foo\\Bar', '/tmp/Outside', 'lowercase', '123Module', 'Foo-Bar', 'Foo Bar', '']);

test('make module refuses an existing module with different casing', function () {
    mkdir($this->moduleSandbox.'/app/Modules/Inventory', 0777, true);
    $this->artisan('make:module', ['name' => 'INVENTORY'])->assertFailed();
    expect(is_dir($this->moduleSandbox.'/app/Modules/INVENTORY'))->toBeFalse();
});

test('make module does not duplicate a provider already registered using an import', function () {
    $contents = <<<'PROVIDERS'
<?php
use App\Modules\Orders\Infrastructure\Providers\OrdersServiceProvider;
return [OrdersServiceProvider::class];
PROVIDERS;
    file_put_contents($this->moduleSandbox.'/bootstrap/providers.php', $contents);
    $this->artisan('make:module', ['name' => 'Orders'])->assertSuccessful();
    expect(file_get_contents($this->moduleSandbox.'/bootstrap/providers.php'))->toBe($contents);
});

test('make module rejects unsupported provider composition before creating a module', function (string $contents) {
    file_put_contents($this->moduleSandbox.'/bootstrap/providers.php', $contents);
    $this->artisan('make:module', ['name' => 'Reporting'])->assertFailed();
    expect(is_dir($this->moduleSandbox.'/app/Modules/Reporting'))->toBeFalse();
})->with(['<?php return array();', '<?php return false;', "<?php // return [\nreturn array();"]);

test('generated provider boots module web routes and discovers local migrations', function () {
    $this->artisan('make:module', ['name' => 'Catalog'])->assertSuccessful();
    $module = $this->moduleSandbox.'/app/Modules/Catalog';
    file_put_contents($module.'/routes/web.php', <<<'ROUTES'
<?php
use Illuminate\Support\Facades\Route;
Route::middleware('web')->get('/catalog-baseline', fn () => 'catalog')->name('catalog.baseline');
ROUTES);
    file_put_contents($module.'/database/migrations/2026_01_01_000000_create_catalog_items.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('catalog_items', function (Blueprint $table): void { $table->id(); }); }
    public function down(): void { Schema::dropIfExists('catalog_items'); }
};
MIGRATION);
    require $module.'/Infrastructure/Providers/CatalogServiceProvider.php';
    $this->app->register(CatalogServiceProvider::class);
    $this->get('/catalog-baseline')->assertOk()->assertSeeText('catalog');
    $this->artisan('route:list', ['--path' => 'catalog-baseline'])->assertSuccessful();
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    $this->assertDatabaseHas('migrations', ['migration' => '2026_01_01_000000_create_catalog_items']);
    $this->artisan('migrate:status')->assertSuccessful();
});

test('provider registration handles a list without a trailing comma and preserves comments', function () {
    $contents = <<<'PROVIDERS'
<?php
return [
    App\Providers\AppServiceProvider::class // existing provider
];
// Example bracket in an instruction: ];
PROVIDERS;
    file_put_contents($this->moduleSandbox.'/bootstrap/providers.php', $contents);
    $this->artisan('make:module', ['name' => 'Shipping'])->assertSuccessful();
    $providers = require $this->moduleSandbox.'/bootstrap/providers.php';
    expect($providers)->toBe([
        AppServiceProvider::class,
        ShippingServiceProvider::class,
    ]);
    expect(file_get_contents($this->moduleSandbox.'/bootstrap/providers.php'))->toContain('// existing provider', '// Example bracket in an instruction: ];');
});

test('make module creates a sibling without affecting another module', function () {
    mkdir($this->moduleSandbox.'/app/Modules/Existing', 0777, true);
    $this->artisan('make:module', ['name' => 'Analytics'])->assertSuccessful();
    expect(is_dir($this->moduleSandbox.'/app/Modules/Existing'))->toBeTrue();
});

test('make module refuses an existing file at the target module path', function () {
    mkdir($this->moduleSandbox.'/app/Modules', 0777, true);
    $path = $this->moduleSandbox.'/app/Modules/Archive';
    file_put_contents($path, 'existing content');
    $this->artisan('make:module', ['name' => 'Archive'])->assertFailed();
    expect(file_get_contents($path))->toBe('existing content');
});
