<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function basename;
use function in_array;
use function preg_match;
use function str_replace;
use function strcasecmp;
use function strtolower;

class MakeModuleSeeder extends Command
{
    protected $signature = 'make:module-seeder {module : An existing StudlyCase module} {name : A single StudlyCase seeder name}';

    protected $description = 'Create an opt-in local/testing seeder in an existing module';

    public function handle(Filesystem $files): int
    {
        $module = $this->argument('module');
        $name = $this->argument('name');
        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $module) !== 1 || preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $name) !== 1 || in_array(strtolower($name), ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'self', 'parent'], true)) {
            $this->error('Use single StudlyCase module and seeder names that are not reserved PHP types.');

            return self::FAILURE;
        }

        $modulePath = app_path('Modules/'.$module);
        if (! $files->isDirectory($modulePath)) {
            $this->error('Module does not exist. Create it with make:module first.');

            return self::FAILURE;
        }

        $source = $this->seederSource($module, $name);
        try {
            \PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (\ParseError) {
            $this->error('Seeder names must be valid PHP class names. No files were changed.');

            return self::FAILURE;
        }

        $seedersPath = $modulePath.'/Infrastructure/Persistence/Seeders';
        foreach ($files->glob($seedersPath.'/*') as $existing) {
            if (strcasecmp(basename($existing), $name.'.php') === 0) {
                $this->error('Seeder already exists. No files were changed.');

                return self::FAILURE;
            }
        }

        $files->ensureDirectoryExists($seedersPath);
        $files->put($seedersPath.'/'.$name.'.php', $source);
        $this->info('Seeder created. Run explicitly with db:seed --class=App\\Modules\\'.$module.'\\Infrastructure\\Persistence\\Seeders\\'.$name.' in local or testing.');

        return self::SUCCESS;
    }

    private function seederSource(string $module, string $name): string
    {
        return str_replace(['{{ module }}', '{{ name }}'], [$module, $name], <<<'PHP_SOURCE'
<?php

namespace App\Modules\{{ module }}\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use LogicException;

class {{ name }} extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data may only be seeded in local or testing environments.');
        }
    }
}

PHP_SOURCE);
    }
}
