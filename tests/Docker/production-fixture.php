<?php

use App\Modules\Identity\Application\Api\InitialAccounts;
use App\Modules\Teams\Application\Api\PersonalTeams;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$connection = DB::connection();
if ($connection->getDriverName() !== 'pgsql' || $connection->getConfig('host') !== 'pgsql' || ! str_starts_with($connection->getDatabaseName(), 'smoke_')) {
    throw new RuntimeException('The fixture only accepts the disposable PostgreSQL smoke service.');
}

$postgresMajor = intdiv((int) DB::selectOne('SHOW server_version_num')->server_version_num, 10000);
if ($postgresMajor !== 16) {
    throw new RuntimeException('The smoke environment requires PostgreSQL 16.');
}

if (getenv('SMOKE_ACTION') === 'initialize') {
    if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException('Disposable smoke migrations failed.');
    }
    if (DB::table('users')->exists() || DB::table('teams')->exists()) {
        throw new RuntimeException('The smoke database must start empty; demo seeders must not run.');
    }

    $password = getenv('SMOKE_TEST_PASSWORD');
    if (! is_string($password) || strlen($password) < 32) {
        throw new RuntimeException('The fixture requires an ephemeral strong test password.');
    }

    $accounts = $app->make(InitialAccounts::class);
    $teams = $app->make(PersonalTeams::class);
    $ownerId = DB::transaction(function () use ($accounts, $teams, $password): int {
        $ownerId = $accounts->create('Smoke Owner', 'owner@smoke.example.invalid', $password);
        $teams->createFor($ownerId, 'Smoke Owner Personal');
        $outsiderId = $accounts->create('Smoke Outsider', 'outsider@smoke.example.invalid', $password);
        $teams->createFor($outsiderId, 'Smoke Outsider Personal');
        DB::table('users')->whereIn('id', [$ownerId, $outsiderId])->update(['email_verified_at' => now()]);

        return $ownerId;
    });

    $result = [
        'driver' => $connection->getDriverName(),
        'postgres_major' => $postgresMajor,
        'owner_id' => $ownerId,
        'personal_team_slug' => DB::table('teams')->where('name', 'Smoke Owner Personal')->value('slug'),
        'outsider_team_slug' => DB::table('teams')->where('name', 'Smoke Outsider Personal')->value('slug'),
        'users' => DB::table('users')->count(),
        'teams' => DB::table('teams')->count(),
    ];
} elseif (getenv('SMOKE_ACTION') === 'verify') {
    $ownerId = DB::table('users')->where('email', 'owner@smoke.example.invalid')->value('id');
    $team = DB::table('teams')->where('slug', 'production-smoke-team')->first();
    $result = [
        'users' => DB::table('users')->count(),
        'teams' => DB::table('teams')->count(),
        'memberships' => DB::table('team_members')->count(),
        'persisted' => $team !== null && DB::table('team_members')->where('team_id', $team->id)->where('user_id', $ownerId)->where('role', 'owner')->exists()
            && DB::table('users')->where('id', $ownerId)->where('current_team_id', $team->id)->exists(),
        'sessions' => DB::table('sessions')->where('user_id', $ownerId)->exists(),
    ];
} else {
    throw new RuntimeException('Unknown disposable smoke fixture action.');
}

echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
