<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Parser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputDefinition;

use function basename;
use function in_array;
use function preg_match;
use function preg_quote;
use function str_replace;
use function strcasecmp;
use function strtolower;
use function var_export;

class MakeModuleConsole extends Command
{
    protected $signature = 'make:module-command {module : An existing StudlyCase module} {name : A single StudlyCase console class} {--signature= : Native Artisan signature using the derived module command name}';

    protected $description = 'Create an Artisan entry in the module Presentation layer';

    public function handle(Filesystem $files): int
    {
        $module = $this->argument('module');
        $name = $this->argument('name');
        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $module) !== 1 || preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $name) !== 1 || in_array(strtolower($name), ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'self', 'parent'], true)) {
            $this->error('Use single StudlyCase module and console names that are not reserved PHP types.');

            return self::FAILURE;
        }

        $modulePath = app_path('Modules/'.$module);
        if (! $files->isDirectory($modulePath)) {
            $this->error('Module does not exist. Create it with make:module first.');

            return self::FAILURE;
        }

        $commandName = Str::kebab($module).':'.Str::kebab($name);
        $signature = $this->option('signature') ?? $commandName;
        if (preg_match('/\A\s*'.preg_quote($commandName, '/').'(?:\s+\{[^{}]+\})*\s*\z/D', $signature) !== 1) {
            $this->error('Use the derived module command name followed by complete native Artisan argument or option definitions: '.$commandName);

            return self::FAILURE;
        }

        try {
            [, $arguments, $options] = Parser::parse($signature);
            new InputDefinition([...$arguments, ...$options]);
            if ($this->getApplication()->has($commandName)) {
                $this->error('Use the derived module command name without replacing an existing Artisan command: '.$commandName);

                return self::FAILURE;
            }
            $source = $this->consoleSource($module, $name, $signature);
            \PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (\InvalidArgumentException|LogicException|\ParseError) {
            $this->error('The console name or Artisan signature is invalid. No files were changed.');

            return self::FAILURE;
        }

        $directory = $modulePath.'/Presentation/Console';
        foreach ($files->glob($directory.'/*') as $existing) {
            if (strcasecmp(basename($existing), $name.'.php') === 0) {
                $this->error('Console command already exists. No files were changed.');

                return self::FAILURE;
            }
        }

        $files->ensureDirectoryExists($directory);
        $files->put($directory.'/'.$name.'.php', $source);
        $this->info('Artisan entry created: '.$commandName.'. Delegate its behavior to the module Application layer.');

        return self::SUCCESS;
    }

    private function consoleSource(string $module, string $name, string $signature): string
    {
        return str_replace(['{{ module }}', '{{ name }}', '{{ signature }}'], [$module, $name, var_export($signature, true)], <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\Presentation\Console;

use Illuminate\Console\Command;
use LogicException;

class {{ name }} extends Command
{
    protected $signature = {{ signature }};

    /**
     * Map CLI input to an Application use case through native method injection.
     */
    public function handle(): int
    {
        throw new LogicException('Implement delegation to the module Application layer before executing this command.');
    }
}

PHP_SOURCE);
    }
}
