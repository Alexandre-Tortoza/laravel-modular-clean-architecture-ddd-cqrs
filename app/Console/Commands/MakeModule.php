<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function count;
use function in_array;
use function preg_match;
use function str_replace;

class MakeModule extends Command
{
    protected $signature = 'make:module {name : A single StudlyCase module name}';

    protected $description = 'Create a minimal module with local routes, migrations, and a registered provider';

    public function handle(Filesystem $files): int
    {
        $name = $this->argument('name');

        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $name) !== 1) {
            $this->error('Use a single StudlyCase module name, for example Billing.');

            return self::FAILURE;
        }

        $modulesPath = app_path('Modules');
        foreach ($files->glob($modulesPath.'/*') as $directory) {
            if (strcasecmp(basename($directory), $name) === 0) {
                $this->error('Module already exists. No files were changed.');

                return self::FAILURE;
            }
        }

        $providersPath = $this->laravel->getBootstrapProvidersPath();
        $contents = $files->get($providersPath);
        $providerClass = 'App\\Modules\\'.$name.'\\Infrastructure\\Providers\\'.$name.'ServiceProvider';
        $providers = require $providersPath;

        $tokens = array_values(array_filter(\PhpToken::tokenize($contents), fn (\PhpToken $token): bool => ! $token->isIgnorable()));
        $returnTokens = array_filter($tokens, fn (\PhpToken $token): bool => $token->id === T_RETURN);

        if (! is_array($providers) || count($returnTokens) !== 1) {
            $this->error('Expected one literal return [...] list in bootstrap/providers.php. No files were changed.');

            return self::FAILURE;
        }

        $returnIndex = array_key_first($returnTokens);
        $openingIndex = $returnIndex + 1;
        if ($tokens[$openingIndex]->text !== '[') {
            $this->error('Provider registration requires a literal array. No files were changed.');

            return self::FAILURE;
        }

        if (! in_array($providerClass, $providers, true)) {
            $closingIndex = $openingIndex;
            $depth = 1;
            while ($depth > 0) {
                $depth += match ($tokens[++$closingIndex]->text) {
                    '[' => 1,
                    ']' => -1,
                    default => 0,
                };
            }

            $closingBracket = $tokens[$closingIndex];
            $separator = in_array($tokens[$closingIndex - 1]->text, ['[', ','], true) ? '' : ',';
            $contents = substr_replace($contents, $separator.'    '.$providerClass.'::class,'.PHP_EOL, $closingBracket->pos, 0);
        }

        $modulePath = $modulesPath.'/'.$name;
        $files->ensureDirectoryExists($modulePath.'/Infrastructure/Providers');
        $files->ensureDirectoryExists($modulePath.'/routes');
        $files->ensureDirectoryExists($modulePath.'/database/migrations');
        $files->put($modulePath.'/Infrastructure/Providers/'.$name.'ServiceProvider.php', $this->providerSource($name));
        $files->put($modulePath.'/routes/web.php', "<?php\n\n// Define this module's web routes here using the web middleware group.\n");
        $files->put($modulePath.'/database/migrations/.gitkeep', '');
        $files->put($providersPath, $contents);

        $this->info('Module '.$name.' created.');

        return self::SUCCESS;
    }

    private function providerSource(string $name): string
    {
        return str_replace('{{ name }}', $name, <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ name }}\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;

class {{ name }}ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}

PHP_SOURCE);
    }
}
