<?php

use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitationSender;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Disposable database migration failed.');
}

$invitation = new TeamInvitationData(1, 'runtime-invitation', 'recipient@example.invalid', 1, 'Runtime Team', 'Runtime Owner', TeamRole::Member);
$sender = $app->make(TeamInvitationSender::class);
if (($argv[1] ?? '') === 'enqueue') {
    $sender->send($invitation);
    echo json_encode(['pending_jobs' => DB::table('jobs')->count()], JSON_THROW_ON_ERROR);
    exit(0);
}

DB::beginTransaction();
$sender->send($invitation);
$beforeRollback = DB::table('jobs')->count();
DB::rollBack();
$afterRollback = DB::table('jobs')->count();
DB::beginTransaction();
$sender->send($invitation);
$beforeCommit = DB::table('jobs')->count();
DB::commit();
$afterCommit = DB::table('jobs')->count();
$workerExit = $kernel->call('queue:work', ['--once' => true, '--tries' => 1, '--timeout' => 60, '--no-interaction' => true]);
echo json_encode([
    'driver' => config('queue.default'),
    'before_rollback' => $beforeRollback,
    'after_rollback' => $afterRollback,
    'before_commit' => $beforeCommit,
    'after_commit' => $afterCommit,
    'worker_exit' => $workerExit,
    'remaining_jobs' => DB::table('jobs')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'mail_count' => Mail::getSymfonyTransport()->messages()->count(),
], JSON_THROW_ON_ERROR);
