<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php AssertFullCoverage.php <coverage-directory>\n");

    exit(1);
}

$coverageDirectory = $argv[1];
$metadata = json_decode((string) file_get_contents($coverageDirectory.'/meta.json'), true, flags: JSON_THROW_ON_ERROR);

if (($metadata['branchCoverage'] ?? false) !== true) {
    fwrite(STDERR, "Branch coverage was not collected.\n");

    exit(1);
}

if (($metadata['executableLines'] ?? 0) !== ($metadata['executedLines'] ?? -1)) {
    fwrite(STDERR, "Not every executable line was covered.\n");

    exit(1);
}

$uncoveredBranches = [];

$unreachableXdebugBranches = [
    'app/Modules/Teams/Domain/Enums/TeamRole.php::App\\Modules\\Teams\\Domain\\Enums\\TeamRole::permissions' => ['22-23', '28'],
    'app/Modules/Teams/Domain/Enums/TeamRole.php::App\\Modules\\Teams\\Domain\\Enums\\TeamRole::hasPermission' => ['34'],
    'app/Modules/Teams/Domain/Enums/TeamRole.php::App\\Modules\\Teams\\Domain\\Enums\\TeamRole::level' => ['40-42'],
    'app/Modules/Teams/Presentation/Rules/TeamName.php::App\\Modules\\Teams\\Presentation\\Rules\\TeamName::validate' => ['20', '22'],
];

foreach (file($coverageDirectory.'/coverage.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $file = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    foreach ($file['symbols'] ?? [] as $symbol) {
        if (! isset($symbol['branches'])) {
            continue;
        }

        $symbolName = $file['file'].'::'.$symbol['name'];
        $uncovered = $symbol['branches']['uncovered'] ?? [];

        if (($unreachableXdebugBranches[$symbolName] ?? null) === $uncovered) {
            continue;
        }

        if ($symbol['branches']['executed'] !== $symbol['branches']['total']) {
            $uncoveredBranches[] = $symbolName;
        }
    }
}

if ($uncoveredBranches !== []) {
    fwrite(STDERR, "Uncovered branches:\n".implode("\n", $uncoveredBranches)."\n");

    exit(1);
}
