<?php

use App\Modules\Example\Presentation\Console\ArchiveEntries;
use App\Modules\Example\Presentation\Console\ImportEntries;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->consoleSandbox = sys_get_temp_dir().'/module-console-'.bin2hex(random_bytes(8));
    mkdir($this->consoleSandbox.'/app/Modules/Example', 0700, true);
    $this->app->setBasePath($this->consoleSandbox);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->consoleSandbox);
});

test('module console creates an Artisan entry separate from application commands', function () {
    $this->artisan('make:module-command', ['module' => 'Example', 'name' => 'ArchiveEntries'])->assertSuccessful();
    $path = $this->consoleSandbox.'/app/Modules/Example/Presentation/Console/ArchiveEntries.php';
    require $path;
    $command = new ArchiveEntries;
    expect($command->getName())->toBe('example:archive-entries');
    expect(fn () => $command->handle())->toThrow(LogicException::class, 'Implement delegation to the module Application layer before executing this command.');
    expect(count((new Filesystem)->allFiles($this->consoleSandbox)))->toBe(1);
});

test('module console safely preserves native arguments options and quoted descriptions', function () {
    $signature = "example:import-entries {path : User's input} {--dry-run}";
    $this->artisan('make:module-command', ['module' => 'Example', 'name' => 'ImportEntries', '--signature' => $signature])->assertSuccessful();
    require $this->consoleSandbox.'/app/Modules/Example/Presentation/Console/ImportEntries.php';
    $command = new ImportEntries;
    expect($command->getDefinition()->getArgument('path')->isRequired())->toBeTrue();
    expect($command->getDefinition()->hasOption('dry-run'))->toBeTrue();
    expect($command->getDefinition()->getArgument('path')->getDescription())->toBe("User's input");
});

test('module console rejects invalid paths names and signatures before writing', function (array $arguments) {
    $this->artisan('make:module-command', array_replace(['module' => 'Example', 'name' => 'ArchiveEntries'], $arguments))->assertFailed();
    expect((new Filesystem)->allFiles($this->consoleSandbox))->toBe([]);
})->with([
    'escaping module' => [['module' => '../Outside']],
    'escaping name' => [['name' => '../Outside']],
    'nested class' => [['name' => 'Nested\\Outside']],
    'missing module' => [['module' => 'Missing']],
    'wrong casing' => [['module' => 'EXAMPLE']],
    'reserved syntax' => [['name' => 'Class']],
    'reserved type' => [['name' => 'Self']],
    'different module command' => [['--signature' => 'app:create-first-user']],
    'empty signature' => [['--signature' => '']],
    'unclosed argument' => [['--signature' => 'example:archive-entries {first']],
    'unparsed signature text' => [['--signature' => 'example:archive-entries first']],
    'missing required argument after optional' => [['--signature' => 'example:archive-entries {first?} {second}']],
    'duplicate arguments' => [['--signature' => 'example:archive-entries {first} {first}']],
]);

test('module console refuses existing targets and reexecution without changing content', function (string $filename) {
    $directory = $this->consoleSandbox.'/app/Modules/Example/Presentation/Console';
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/'.$filename, 'user-owned content');
    $this->artisan('make:module-command', ['module' => 'Example', 'name' => 'ArchiveEntries'])->assertFailed();
    expect(file_get_contents($directory.'/'.$filename))->toBe('user-owned content');
    expect(count((new Filesystem)->allFiles($this->consoleSandbox)))->toBe(1);
})->with(['ArchiveEntries.php', 'ARCHIVEENTRIES.php']);

test('module console does not overwrite a registered Artisan signature', function () {
    $command = new Command;
    $command->setName('example:archive-entries');
    $this->app->make(Kernel::class)->registerCommand($command);
    $this->artisan('make:module-command', ['module' => 'Example', 'name' => 'ArchiveEntries'])->assertFailed();
    expect((new Filesystem)->allFiles($this->consoleSandbox))->toBe([]);
});

test('module console preserves existing entries when a different command is generated', function () {
    $directory = $this->consoleSandbox.'/app/Modules/Example/Presentation/Console';
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/Existing.php', 'preserve existing command');
    $this->artisan('make:module-command', ['module' => 'Example', 'name' => 'OtherCommand'])->assertSuccessful();
    expect(file_get_contents($directory.'/Existing.php'))->toBe('preserve existing command');
    expect(is_file($directory.'/OtherCommand.php'))->toBeTrue();
});
