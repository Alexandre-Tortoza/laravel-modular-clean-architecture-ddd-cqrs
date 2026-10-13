<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Support\Str;

use function basename;
use function dirname;
use function in_array;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function str_replace;
use function strcasecmp;
use function strtolower;

class MakeModuleArtifact extends Command
{
    protected $signature = 'make:module-artifact {module : An existing StudlyCase module} {type : model, factory, controller, request, or migration} {name : A class name, model name for factory, or snake_case migration name} {--factory : Also generate and wire the model factory}';

    protected $description = 'Delegate optional module resources to native Artisan generators';

    private const array DIRECTORIES = [
        'model' => 'Infrastructure/Persistence/Eloquent/Models',
        'factory' => 'Infrastructure/Persistence/Eloquent/Factories',
        'controller' => 'Presentation/Http/Controllers',
        'request' => 'Presentation/Http/Requests',
        'migration' => 'database/migrations',
    ];

    public function handle(Filesystem $files): int
    {
        $module = $this->argument('module');
        $type = $this->argument('type');
        $name = $this->argument('name');
        $withFactory = $this->option('factory');
        if (! isset(self::DIRECTORIES[$type]) || ($withFactory && $type !== 'model')) {
            $this->error('Choose model, factory, controller, request, or migration. --factory is only available for model.');

            return self::FAILURE;
        }

        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/D', $module) !== 1 || preg_match($type === 'migration' ? '/\A[a-z][a-z0-9_]*\z/D' : '/\A[A-Z][A-Za-z0-9]*\z/D', $name) !== 1) {
            $this->error('Use a single StudlyCase module/class name or snake_case migration name.');

            return self::FAILURE;
        }

        if ($type !== 'migration') {
            if (in_array(strtolower($name), ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'self', 'parent'], true)) {
                $this->error('Reserved PHP types cannot be class names.');

                return self::FAILURE;
            }

            try {
                \PhpToken::tokenize('<?php class '.$name.' {}', TOKEN_PARSE);
            } catch (\ParseError) {
                $this->error('Use a valid PHP class name.');

                return self::FAILURE;
            }
        }

        $modulePath = app_path('Modules/'.$module);
        if (! $files->isDirectory($modulePath)) {
            $this->error('Module does not exist. Create it with make:module first.');

            return self::FAILURE;
        }

        $modelClass = 'App\\Modules\\'.$module.'\\Infrastructure\\Persistence\\Eloquent\\Models\\'.$name;
        $modelPath = $modulePath.'/'.self::DIRECTORIES['model'].'/'.$name.'.php';
        if ($type === 'factory' && ! $files->isFile($modelPath)) {
            $this->error('Generate the infrastructure model before its independent factory.');

            return self::FAILURE;
        }

        $factoryClass = 'App\\Modules\\'.$module.'\\Infrastructure\\Persistence\\Eloquent\\Factories\\'.$name.'Factory';
        $factoryPath = $modulePath.'/'.self::DIRECTORIES['factory'].'/'.$name.'Factory.php';
        $directory = $modulePath.'/'.self::DIRECTORIES[$type];
        $filename = $name.($type === 'factory' ? 'Factory' : '').'.php';
        $targets = [$directory.'/'.$filename];
        if ($withFactory) {
            $targets[] = $factoryPath;
        }
        foreach ($targets as $target) {
            foreach ($files->glob(dirname($target).'/*') as $existing) {
                $collides = $type === 'migration'
                    ? str_ends_with(strtolower(basename($existing)), '_'.$name.'.php')
                    : strcasecmp(basename($existing), basename($target)) === 0;
                if ($collides) {
                    $this->error('A target already exists. No files were changed.');

                    return self::FAILURE;
                }
            }
        }

        if ($type === 'migration') {
            return $this->call('make:migration', ['name' => $name, '--path' => 'app/Modules/'.$module.'/database/migrations', '--no-interaction' => true]);
        }
        if ($type === 'model' || $type === 'factory') {
            $factory = $this->factoryGenerator($files, $factoryClass, $factoryPath, $modelClass);
            if ($type === 'factory') {
                return $this->call($factory, ['name' => $factoryClass, '--model' => $modelClass, '--no-interaction' => true]);
            }

            return $this->call($this->modelGenerator($files, $factory, $factoryClass, $modelClass), ['name' => $modelClass, '--factory' => $withFactory, '--no-interaction' => true]);
        }

        $class = 'App\\Modules\\'.$module.'\\'.str_replace('/', '\\', self::DIRECTORIES[$type]).'\\'.$name;

        return $this->call('make:'.$type, ['name' => $class, '--no-interaction' => true]);
    }

    private function factoryGenerator(Filesystem $files, string $factoryClass, string $factoryPath, string $modelClass): FactoryMakeCommand
    {
        return new class($files, $factoryClass, $factoryPath, $modelClass) extends FactoryMakeCommand
        {
            public function __construct(Filesystem $files, private readonly string $factoryClass, private readonly string $factoryPath, private readonly string $modelClass)
            {
                parent::__construct($files);
            }

            protected function getNamespace(mixed $name): string
            {
                return Str::beforeLast($this->factoryClass, '\\');
            }

            protected function getPath(mixed $name): string
            {
                return $this->factoryPath;
            }

            /** @param string $name */
            protected function buildClass(mixed $name): string
            {
                $source = parent::buildClass($name);
                $nativeClass = class_basename(Str::ucfirst(str_replace('Factory', '', $name))).'Factory';
                $declaration = '#[\\Illuminate\\Database\\Eloquent\\Factories\\Attributes\\UseModel(\\'.$this->modelClass.'::class)]'.PHP_EOL.'$1class '.class_basename($this->factoryClass).' extends Factory';

                return (string) preg_replace('/^((?:(?:final|abstract|readonly)\s+)*)class '.$nativeClass.' extends Factory/m', $declaration, $source);
            }
        };
    }

    private function modelGenerator(Filesystem $files, FactoryMakeCommand $factory, string $factoryClass, string $modelClass): ModelMakeCommand
    {
        return new class($files, $factory, $factoryClass, $modelClass) extends ModelMakeCommand
        {
            public function __construct(Filesystem $files, private readonly FactoryMakeCommand $factory, private readonly string $factoryClass, private readonly string $modelClass)
            {
                parent::__construct($files);
            }

            protected function createFactory(): void
            {
                $this->call($this->factory, ['name' => $this->factoryClass, '--model' => $this->modelClass, '--no-interaction' => true]);
            }

            /** @return array<string, string> */
            protected function buildFactoryReplacements(): array
            {
                if (! $this->option('factory')) {
                    return parent::buildFactoryReplacements();
                }

                $factoryName = class_basename($this->factoryClass);

                return [
                    '{{ factoryImport }}' => 'use '.$this->factoryClass.';'.PHP_EOL.'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;',
                    '{{ factory }}' => '/** @use HasFactory<'.$factoryName.'> */'.PHP_EOL.'    use HasFactory;'.PHP_EOL.PHP_EOL.'    protected static function newFactory(): '.$factoryName.PHP_EOL.'    {'.PHP_EOL.'        return '.$factoryName.'::new();'.PHP_EOL.'    }',
                ];
            }
        };
    }
}
