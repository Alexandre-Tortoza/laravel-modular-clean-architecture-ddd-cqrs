<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function basename;
use function dirname;
use function in_array;
use function preg_match;
use function str_replace;
use function strcasecmp;
use function strtolower;

class MakeModuleQuery extends Command
{
    protected $signature = 'make:module-query {module : An existing StudlyCase module} {name : A single StudlyCase query name} {--contract : Create an application contract and an infrastructure implementation} {--test : Create a pending Pest test}';

    protected $description = 'Create an explicitly requested read-side service with a readonly output';

    public function handle(Filesystem $files): int
    {
        $module = $this->argument('module');
        $name = $this->argument('name');

        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $module) !== 1 || preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $name) !== 1 || in_array(strtolower($name), ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'self', 'parent'], true)) {
            $this->error('Use single StudlyCase module and query names that are not reserved PHP types, for example Billing InvoiceSummary.');

            return self::FAILURE;
        }

        $modulePath = app_path('Modules/'.$module);
        if (! $files->isDirectory($modulePath)) {
            $this->error('Module does not exist. Create it with make:module first.');

            return self::FAILURE;
        }

        $withContract = (bool) $this->option('contract');
        $layer = $withContract ? 'Infrastructure' : 'Application';
        $sources = [
            $modulePath.'/Application/Data/'.$name.'Data.php' => $this->projectionSource($module, $name),
            $modulePath.'/'.$layer.'/Queries/'.$name.'.php' => $this->querySource($module, $name, $withContract),
        ];
        if ($withContract) {
            $sources[$modulePath.'/Application/Contracts/'.$name.'Query.php'] = $this->contractSource($module, $name);
        }

        try {
            foreach ($sources as $source) {
                \PhpToken::tokenize($source, TOKEN_PARSE);
            }
        } catch (\ParseError) {
            $this->error('Query names must be valid PHP class names. No files were changed.');

            return self::FAILURE;
        }

        if ($this->option('test')) {
            $sources[base_path('tests/Unit/Modules/'.$module.'/'.$name.'Test.php')] = "<?php\n\ntest('{$module} {$name} fulfills its read behavior')->todo();\n";
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

        $this->info('Query '.$module.' '.$name.' created. Implement it before querying it.');
        if ($withContract) {
            $this->line('Bind the application contract to its infrastructure implementation in your module provider when ready.');
        }

        return self::SUCCESS;
    }

    private function projectionSource(string $module, string $name): string
    {
        return str_replace(['{{ module }}', '{{ name }}'], [$module, $name], <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\Application\Data;

readonly class {{ name }}Data
{
}

PHP_SOURCE);
    }

    private function querySource(string $module, string $name, bool $withContract): string
    {
        return str_replace(
            ['{{ module }}', '{{ name }}', '{{ layer }}', '{{ contract import }}', '{{ implements }}'],
            [$module, $name, $withContract ? 'Infrastructure' : 'Application', $withContract ? 'use App\\Modules\\'.$module.'\\Application\\Contracts\\'.$name.'Query;'.PHP_EOL : '', $withContract ? ' implements '.$name.'Query' : ''],
            <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\{{ layer }}\Queries;

{{ contract import }}use App\Modules\{{ module }}\Application\Data\{{ name }}Data;
use LogicException;

class {{ name }}{{ implements }}
{
    public function handle(): {{ name }}Data
    {
        throw new LogicException('Implement {{ name }} before querying it.');
    }
}

PHP_SOURCE);
    }

    private function contractSource(string $module, string $name): string
    {
        return str_replace(['{{ module }}', '{{ name }}'], [$module, $name], <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\Application\Contracts;

use App\Modules\{{ module }}\Application\Data\{{ name }}Data;

interface {{ name }}Query
{
    public function handle(): {{ name }}Data;
}

PHP_SOURCE);
    }
}
