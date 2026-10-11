<?php

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

test('application contracts do not expose framework or infrastructure types', function () {
    $contracts = [
        'App\\Modules\\Teams\\Application\\Contracts\\CreatesTeams',
        'App\\Modules\\Teams\\Application\\Contracts\\ManagesTeamInvitations',
        'App\\Modules\\Teams\\Application\\Contracts\\ManagesTeams',
        'App\\Modules\\Teams\\Application\\Contracts\\RunsTeamTransactions',
        'App\\Modules\\Teams\\Application\\Contracts\\TeamQueries',
        'App\\Modules\\Profile\\Application\\Contracts\\DeletesProfiles',
        'App\\Modules\\Profile\\Application\\Contracts\\UpdatesProfiles',
        'App\\Modules\\Security\\Application\\Contracts\\UpdatesPasswords',
    ];

    $namesFor = static function (?ReflectionType $type): array {
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return array_merge(...array_map(
                static fn (ReflectionNamedType $namedType): array => $namedType->isBuiltin() ? [] : [$namedType->getName()],
                $type->getTypes(),
            ));
        }

        return [];
    };

    $exposedTypes = [];

    foreach ($contracts as $contract) {
        $reflection = new ReflectionClass($contract);

        expect($reflection->isInterface())->toBeTrue();

        foreach ($reflection->getMethods() as $method) {
            $types = $namesFor($method->getReturnType());

            foreach ($method->getParameters() as $parameter) {
                $types = [...$types, ...$namesFor($parameter->getType())];
            }

            foreach ($types as $type) {
                $exposedTypes[] = $type;
            }
        }
    }

    foreach ($exposedTypes as $type) {
        expect($type)->not->toMatch('/^(App\\\\Modules\\\\[^\\\\]+\\\\Infrastructure|App\\\\Models|Illuminate|Laravel|Inertia)\\\\/');
    }
});
