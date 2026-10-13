<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function basename;
use function dirname;
use function preg_match;
use function str_replace;
use function strcasecmp;

class MakeModuleUsecase extends Command
{
    protected $signature = 'make:module-usecase {module : An existing StudlyCase module} {name : A single StudlyCase use case name} {--test : Create a pending Pest test}';

    protected $description = 'Create a readonly command and its handler in an existing module';

    public function handle(Filesystem $files): int
    {
        $module = $this->argument('module');
        $name = $this->argument('name');

        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $module) !== 1 || preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $name) !== 1) {
            $this->error('Use single StudlyCase module and use case names, for example Billing IssueInvoice.');

            return self::FAILURE;
        }

        $modulePath = app_path('Modules/'.$module);
        if (! $files->isDirectory($modulePath)) {
            $this->error('Module does not exist. Create it with make:module first.');

            return self::FAILURE;
        }

        $commandsPath = $modulePath.'/Application/Commands';
        $sources = [
            $commandsPath.'/'.$name.'.php' => $this->commandSource($module, $name),
            $commandsPath.'/'.$name.'Handler.php' => $this->handlerSource($module, $name),
        ];
        try {
            foreach ($sources as $source) {
                \PhpToken::tokenize($source, TOKEN_PARSE);
            }
        } catch (\ParseError) {
            $this->error('Use case names must be valid PHP class names. No files were changed.');

            return self::FAILURE;
        }

        if ($this->option('test')) {
            $sources[base_path('tests/Unit/Modules/'.$module.'/'.$name.'Test.php')] = "<?php\n\ntest('{$module} {$name} fulfills its behavior')->todo();\n";
        }

        foreach ($sources as $path => $source) {
            foreach ($files->glob(dirname($path).'/*') as $existing) {
                if (strcasecmp(basename($existing), basename($path)) === 0) {
                    $this->error('A target already exists. No files were changed.');

                    return self::FAILURE;
                }
            }
        }

        foreach ($sources as $path => $source) {
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $source);
        }

        $this->info('Use case '.$module.' '.$name.' created. Implement the handler before executing it.');

        return self::SUCCESS;
    }

    private function commandSource(string $module, string $name): string
    {
        return str_replace(['{{ module }}', '{{ name }}'], [$module, $name], <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\Application\Commands;

readonly class {{ name }}
{
}

PHP_SOURCE);
    }

    private function handlerSource(string $module, string $name): string
    {
        return str_replace(['{{ module }}', '{{ name }}'], [$module, $name], <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\Application\Commands;

use LogicException;

class {{ name }}Handler
{
    public function handle({{ name }} $command): void
    {
        throw new LogicException('Implement {{ name }} before executing it.');
    }
}

PHP_SOURCE);
    }
}
