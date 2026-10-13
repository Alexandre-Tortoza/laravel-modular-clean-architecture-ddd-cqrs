<?php

use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitationSender;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if ($connection->getDriverName() !== 'pgsql' || $connection->getConfig('host') !== 'pgsql' || ! str_starts_with($connection->getDatabaseName(), 'smoke_cycle_')) {
    throw new RuntimeException('Cycle fixtures require their own disposable PostgreSQL database.');
}

$action = getenv('CYCLE_ACTION');
if ($action === 'expand') {
    DB::table('users')->where('email', 'owner@smoke.example.invalid')->update(['release_compatibility_note' => 'retained-after-rollback']);
} elseif ($action === 'enqueue') {
    $invitation = new TeamInvitationData(1, 'cycle-invitation', 'recipient@smoke.example.invalid', 1, 'Cycle Team', 'Cycle Owner', TeamRole::Member);
    $app->make(TeamInvitationSender::class)->send($invitation);
} elseif ($action !== 'verify') {
    throw new RuntimeException('Unknown disposable cycle action.');
}

$ownerId = DB::table('users')->where('email', 'owner@smoke.example.invalid')->value('id');
$team = DB::table('teams')->where('slug', 'release-cycle-team')->first();
echo json_encode([
    'users' => DB::table('users')->count(),
    'teams' => DB::table('teams')->count(),
    'memberships' => DB::table('team_members')->count(),
    'owner_id' => $ownerId,
    'persisted' => $team !== null && DB::table('team_members')->where('team_id', $team->id)->where('user_id', $ownerId)->where('role', 'owner')->exists(),
    'session_exists' => DB::table('sessions')->where('user_id', $ownerId)->exists(),
    'expanded' => Schema::hasColumn('users', 'release_compatibility_note'),
    'expanded_value' => Schema::hasColumn('users', 'release_compatibility_note') ? DB::table('users')->where('id', $ownerId)->value('release_compatibility_note') : null,
    'jobs' => DB::table('jobs')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'migration_receipts' => DB::table('_template_release_migrations')->count(),
], JSON_THROW_ON_ERROR).PHP_EOL;
