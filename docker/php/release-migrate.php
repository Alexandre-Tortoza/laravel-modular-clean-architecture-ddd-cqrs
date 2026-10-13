<?php

use Illuminate\Contracts\Console\Kernel;

function failReleaseMigrations(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}

$revision = getenv('RELEASE_REVISION') ?: '';
$timeout = getenv('RELEASE_MIGRATION_LOCK_TIMEOUT');
$timeout = $timeout === false ? '60' : $timeout;
if (preg_match('/\A[a-f0-9]{40}\z/D', $revision) !== 1) {
    failReleaseMigrations('Select the full source revision of the approved immutable release.');
}
if (preg_match('/\A[0-9]+\z/D', $timeout) !== 1 || (int) $timeout > 3600) {
    failReleaseMigrations('Select an integer lock timeout from 0 to 3600 seconds.');
}

$lock = null;
try {
    $root = dirname(__DIR__, 2);
    $manifest = file_get_contents($root.'/public/build/release.json');
    if ($manifest === false || (json_decode($manifest, true, flags: JSON_THROW_ON_ERROR)['source_revision'] ?? null) !== $revision) {
        failReleaseMigrations('Release revision does not match the built artifact.');
    }
    $fingerprint = hash('sha256', $manifest);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $database = $app->make('db');
    $configuration = $database->connection()->getConfig();
    if (($configuration['driver'] ?? null) !== 'pgsql') {
        failReleaseMigrations('Controlled release migrations require PostgreSQL.');
    }

    // Keep the lock on its own session, including across transactional migrations.
    $configuration['name'] = 'release_migration_lock';
    $lock = $database->build($configuration)->getPdo();
    $deadline = hrtime(true) + ((int) $timeout * 1_000_000_000);
    while ((int) $lock->query('SELECT pg_try_advisory_lock(1280131922, 1)::integer')->fetchColumn() !== 1) {
        if (hrtime(true) >= $deadline) {
            failReleaseMigrations('Migration lock wait timed out; the release must not proceed.');
        }
        usleep(100_000);
    }

    // This operational receipt is independent of application schema and contains no business data.
    $lock->exec('CREATE TABLE IF NOT EXISTS _template_release_migrations (source_revision varchar(40) PRIMARY KEY, artifact_fingerprint varchar(64) NOT NULL, completed_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $receipt = $lock->prepare('SELECT artifact_fingerprint FROM _template_release_migrations WHERE source_revision = ?');
    $receipt->execute([$revision]);
    $previous = $receipt->fetchColumn();
    if ($previous !== false) {
        if ($previous !== $fingerprint) {
            failReleaseMigrations('A completed release revision cannot be reused with a different artifact.');
        }
        fwrite(STDOUT, 'Migrations already completed for this release.'.PHP_EOL);
        exit(0);
    }

    if ($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
        failReleaseMigrations('Migrations failed; the release must not proceed.');
    }
    $receipt = $lock->prepare('INSERT INTO _template_release_migrations (source_revision, artifact_fingerprint) VALUES (?, ?)');
    $receipt->execute([$revision, $fingerprint]);
    fwrite(STDOUT, 'Migrations completed for release '.$revision.PHP_EOL);
} catch (Throwable) {
    failReleaseMigrations('Release migrations failed. Review the approved migration and private database configuration before retrying.');
} finally {
    // Normal exit and process termination also release PostgreSQL session locks.
    if ($lock instanceof PDO) {
        $lock->query('SELECT pg_advisory_unlock(1280131922, 1)');
    }
}
