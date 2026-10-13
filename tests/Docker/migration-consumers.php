<?php

use App\Modules\Identity\Application\Api\InitialAccounts;
use App\Modules\Teams\Application\Api\PersonalTeams;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($connection->getDriverName() !== 'pgsql' || $connection->getConfig('host') !== 'pgsql' || ! str_starts_with($connection->getDatabaseName(), 'smoke_')) {
    throw new RuntimeException('Release fixtures require their own disposable PostgreSQL smoke database.');
}
$accounts = $app->make(InitialAccounts::class);
$password = getenv('SMOKE_TEST_PASSWORD');
if (getenv('SMOKE_MIGRATION_ACTION') === 'initialize') {
    if ($accounts->exists()) {
        throw new RuntimeException('The release fixture must initially have no accounts.');
    }
    DB::transaction(function () use ($app, $accounts, $password): void {
        $id = $accounts->create('Release Compatibility', 'compatibility@smoke.example.invalid', $password);
        $app->make(PersonalTeams::class)->createFor($id, 'Release Compatibility Personal');
    });
} elseif (getenv('SMOKE_MIGRATION_ACTION') === 'expanded') {
    DB::table('users')->where('email', 'compatibility@smoke.example.invalid')->update(['release_compatibility_note' => 'expanded']);
    if (DB::table('users')->value('release_compatibility_note') !== 'expanded') {
        throw new RuntimeException('The candidate schema cannot read and write its new field.');
    }
}

// The retained consumer uses its existing authentication contract and no new field.
$provider = Auth::getProvider();
$credentials = ['email' => 'compatibility@smoke.example.invalid', 'password' => $password];
$user = $provider->retrieveByCredentials($credentials);
if (! $accounts->exists() || $user === null || ! $provider->validateCredentials($user, $credentials) || DB::table('users')->count() !== 1 || DB::table('teams')->count() !== 1) {
    throw new RuntimeException('The retained account and personal team contract regressed after expansion.');
}
echo 'Account and personal team compatibility passed.'.PHP_EOL;
