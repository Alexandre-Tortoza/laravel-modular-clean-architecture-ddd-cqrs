<?php

namespace Tests\Architecture\Support;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ModuleBoundaryInspector
{
    /** @var array<string, list<string>> */
    private array $filesByModule = [];

    /** @var array<string, list<Node>> */
    private array $nodesByFile = [];

    /** @var array<string, true> */
    private array $publicSymbols = [];

    public function __construct(private readonly string $root)
    {
        $modulesDirectory = $root.'/app/Modules';

        if (! is_dir($modulesDirectory)) {
            return;
        }

        foreach (new \DirectoryIterator($modulesDirectory) as $directory) {
            if ($directory->isDot() || ! $directory->isDir()) {
                continue;
            }

            $files = $this->phpFiles($directory->getPathname());

            if ($files !== []) {
                $this->filesByModule[$directory->getFilename()] = $files;
            }
        }

        ksort($this->filesByModule);

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                if (! str_contains($file, '/Application/Api/')) {
                    continue;
                }

                $namespace = 'App\\Modules\\'.$module.'\\Application\\Api\\';
                $this->publicSymbols[$namespace.pathinfo($file, PATHINFO_FILENAME)] = true;

                foreach ($this->apiSignatureTypes($file) as $dependency) {
                    if (str_starts_with($dependency, 'App\\Modules\\'.$module.'\\Application\\Data\\')
                        || str_starts_with($dependency, 'App\\Modules\\'.$module.'\\Domain\\Enums\\')) {
                        $this->publicSymbols[$dependency] = true;
                    }
                }
            }
        }
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_keys($this->filesByModule);
    }

    /** @return list<string> */
    public function layerViolations(): array
    {
        $violations = [];

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                $relative = $this->relative($file);
                $isDomain = str_contains($relative, "/$module/Domain/");
                $isApplication = str_contains($relative, "/$module/Application/");

                if (! $isDomain && ! $isApplication) {
                    continue;
                }

                foreach ($this->dependencies($file) as $dependency) {
                    $framework = preg_match('/^(Illuminate|Laravel|Inertia|Symfony\\\\Component\\\\HttpFoundation|Psr\\\\Container)\\\\/', $dependency) === 1;
                    $globalApp = str_starts_with($dependency, 'App\\')
                        && ! str_starts_with($dependency, 'App\\Modules\\');
                    $domainOutside = $isDomain && str_starts_with($dependency, 'App\\Modules\\')
                        && ! str_starts_with($dependency, "App\\Modules\\$module\\Domain\\");
                    $internal = str_starts_with($dependency, "App\\Modules\\$module\\Infrastructure\\")
                        || str_starts_with($dependency, "App\\Modules\\$module\\Presentation\\");
                    $domainToApplication = $isDomain && preg_match('/^App\\\\Modules\\\\[^\\\\]+\\\\Application\\\\/', $dependency) === 1;

                    if ($framework || $globalApp || $domainOutside || $internal || $domainToApplication) {
                        $violations[] = "$relative uses $dependency";
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public function publicBoundaryViolations(): array
    {
        $violations = [];

        foreach ($this->phpFiles($this->root.'/app') as $file) {
            $relative = $this->relative($file);
            $sourceModule = $this->moduleOf($relative);

            foreach ($this->dependencies($file) as $dependency) {
                if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\/', $dependency, $matches) !== 1) {
                    continue;
                }

                $targetModule = $matches[1];

                if ($sourceModule === $targetModule) {
                    continue;
                }

                if (! isset($this->publicSymbols[$dependency])) {
                    $violations[] = "$relative uses private $dependency";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public function cycleViolations(): array
    {
        $edges = array_fill_keys($this->modules(), []);

        foreach ($this->filesByModule as $source => $files) {
            foreach ($files as $file) {
                foreach ($this->dependencies($file) as $dependency) {
                    if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\/', $dependency, $matches) === 1
                        && $matches[1] !== $source && isset($edges[$matches[1]])) {
                        $edges[$source][$matches[1]] = true;
                    }
                }
            }
        }

        $cycles = [];
        $visited = [];
        $stack = [];

        $visit = function (string $module) use (&$visit, &$cycles, &$visited, &$stack, $edges): void {
            $visited[$module] = true;
            $stack[] = $module;

            foreach (array_keys($edges[$module]) as $dependency) {
                $position = array_search($dependency, $stack, true);

                if ($position !== false) {
                    $cycles[] = implode(' -> ', [...array_slice($stack, $position), $dependency]);
                } elseif (! isset($visited[$dependency])) {
                    $visit($dependency);
                }
            }

            array_pop($stack);
        };

        foreach ($this->modules() as $module) {
            if (! isset($visited[$module])) {
                $visit($module);
            }
        }

        return array_values(array_unique($cycles));
    }

    /** @return list<string> */
    public function immutableValueViolations(): array
    {
        $violations = [];

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                $relative = $this->relative($file);

                if (! str_contains($relative, "/$module/Application/Data/")
                    && ! str_contains($relative, "/$module/Application/Commands/")) {
                    continue;
                }

                foreach ((new NodeFinder)->findInstanceOf($this->nodes($file), Class_::class) as $class) {
                    $isCommand = str_contains($relative, '/Commands/') && ! str_ends_with($class->name?->toString() ?? '', 'Handler');

                    if (($isCommand || str_contains($relative, '/Data/')) && ! $class->isReadonly()) {
                        $violations[] = "$relative must declare a readonly value class";
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function internalContractViolations(): array
    {
        $violations = [];

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                if (! str_contains($this->relative($file), "/$module/Application/Contracts/")) {
                    continue;
                }

                if ((new NodeFinder)->findInstanceOf($this->nodes($file), Interface_::class) === []) {
                    $violations[] = $this->relative($file).' must declare an interface';
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function misplacedTransactionViolations(): array
    {
        $violations = [];

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                $relative = $this->relative($file);

                if (! str_contains($relative, "/$module/Infrastructure/Persistence/Eloquent/Repositories/")) {
                    continue;
                }

                foreach ((new NodeFinder)->findInstanceOf($this->nodes($file), Node\Expr\StaticCall::class) as $call) {
                    if ($call->class instanceof Name
                        && $call->class->toString() === 'Illuminate\\Support\\Facades\\DB'
                        && $call->name instanceof Node\Identifier
                        && in_array($call->name->toString(), ['transaction', 'beginTransaction', 'commit', 'rollBack'], true)) {
                        $violations[] = "$relative starts or controls a database transaction";
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public function debugHelperViolations(): array
    {
        $violations = [];

        foreach ($this->filesByModule as $files) {
            foreach ($files as $file) {
                foreach ((new NodeFinder)->findInstanceOf($this->nodes($file), Node\Expr\FuncCall::class) as $call) {
                    if ($call->name instanceof Name && in_array($call->name->toString(), ['dd', 'dump', 'ray'], true)) {
                        $violations[] = $this->relative($file).' invokes '.$call->name->toString();
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public function eloquentPlacementViolations(): array
    {
        $violations = [];

        foreach ($this->phpFiles($this->root.'/app/Models') as $file) {
            $violations[] = $this->relative($file).' is an Eloquent resource outside a module';
        }

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                $relative = $this->relative($file);

                foreach ((new NodeFinder)->findInstanceOf($this->nodes($file), Class_::class) as $class) {
                    $extends = $class->extends?->toString();
                    $isModel = in_array($extends, [
                        'Illuminate\\Database\\Eloquent\\Model',
                        'Illuminate\\Database\\Eloquent\\Relations\\Pivot',
                        'Illuminate\\Foundation\\Auth\\User',
                    ], true);
                    $isFactory = $extends === 'Illuminate\\Database\\Eloquent\\Factories\\Factory';

                    if ($isModel && ! str_contains($relative, "/$module/Infrastructure/Persistence/Eloquent/Models/")) {
                        $violations[] = "$relative places an Eloquent model outside infrastructure";
                    }

                    if ($isFactory && ! str_contains($relative, "/$module/Infrastructure/Persistence/Eloquent/Factories/")) {
                        $violations[] = "$relative places an Eloquent factory outside infrastructure";
                    }

                    if (str_contains($relative, "/$module/Infrastructure/Persistence/Eloquent/Models/") && ! $isModel) {
                        $violations[] = "$relative declares a model without an Eloquent model parent";
                    }

                    if (str_contains($relative, "/$module/Infrastructure/Persistence/Eloquent/Factories/") && ! $isFactory) {
                        $violations[] = "$relative declares a factory without an Eloquent factory parent";
                    }
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function publicTypeViolations(): array
    {
        $violations = [];

        foreach ($this->filesByModule as $module => $files) {
            foreach ($files as $file) {
                $relative = $this->relative($file);
                $symbol = 'App\\Modules\\'.$module.'\\Application\\Data\\'.pathinfo($file, PATHINFO_FILENAME);

                if (! str_contains($relative, '/Application/Api/') && ! isset($this->publicSymbols[$symbol])) {
                    continue;
                }

                foreach ((new NodeFinder)->find($this->nodes($file), static fn (Node $node): bool => $node instanceof ClassMethod || $node instanceof Node\Stmt\Property) as $member) {
                    $types = [];
                    $docblock = $member->getDocComment()?->getText() ?? '';

                    if (preg_match('/(?:Illuminate|Laravel|Inertia|Symfony\\\\Component\\\\HttpFoundation|Psr\\\\Container|App\\\\Models)\\\\/', $docblock) === 1
                        || preg_match('/App\\\\Modules\\\\[^\\\\]+\\\\(?:Infrastructure|Presentation)\\\\/', $docblock) === 1) {
                        $violations[] = "$relative documents a framework or private adapter type";
                    }

                    if ($member instanceof ClassMethod) {
                        $types[] = $member->returnType;

                        foreach ($member->params as $parameter) {
                            $types[] = $parameter->type;
                        }
                    } else {
                        $types[] = $member->type;
                    }

                    foreach ($types as $type) {
                        foreach ($this->typeNames($type) as $name) {
                            if (preg_match('/^(Illuminate|Laravel|Inertia)\\\\/', $name) === 1
                                || str_starts_with($name, 'App\\Models\\')
                                || preg_match('/^App\\\\Modules\\\\[^\\\\]+\\\\(Infrastructure|Presentation)\\\\/', $name) === 1) {
                                $violations[] = "$relative exposes $name";
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    private function dependencies(string $file): array
    {
        $names = (new NodeFinder)->findInstanceOf($this->nodes($file), Name::class);
        $dependencies = [];

        foreach ($names as $name) {
            $dependencies[] = $name->toString();
        }

        return array_values(array_unique($dependencies));
    }

    /** @return list<string> */
    private function apiSignatureTypes(string $file): array
    {
        $nodes = $this->nodes($file);
        $imports = [];
        $types = [];

        foreach ((new NodeFinder)->findInstanceOf($nodes, Use_::class) as $statement) {
            foreach ($statement->uses as $use) {
                $imports[$use->alias?->toString() ?? $use->name->getLast()] = $use->name->toString();
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($nodes, ClassMethod::class) as $method) {
            $types = [...$types, ...$this->typeNames($method->returnType)];

            foreach ($method->params as $parameter) {
                $types = [...$types, ...$this->typeNames($parameter->type)];
            }

            $docblock = $method->getDocComment()?->getText() ?? '';

            foreach ($imports as $alias => $name) {
                if (preg_match('/\b'.preg_quote($alias, '/').'\b/', $docblock) === 1) {
                    $types[] = $name;
                }
            }
        }

        return array_values(array_unique($types));
    }

    /** @return list<Node> */
    private function nodes(string $file): array
    {
        if (isset($this->nodesByFile[$file])) {
            return $this->nodesByFile[$file];
        }

        $source = file_get_contents($file);

        if ($source === false) {
            throw new \RuntimeException("Could not read $file");
        }

        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        return $this->nodesByFile[$file] = $traverser->traverse($nodes ?? []);
    }

    /** @return list<string> */
    private function typeNames(?Node $type): array
    {
        if ($type === null) {
            return [];
        }

        return array_map(
            static fn (Name $name): string => $name->toString(),
            (new NodeFinder)->findInstanceOf([$type], Name::class),
        );
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen($this->root) + 1);
    }

    private function moduleOf(string $relative): ?string
    {
        return preg_match('#^app/Modules/([^/]+)/#', $relative, $matches) === 1 ? $matches[1] : null;
    }
}
