<?php

namespace App\Modules\Teams\Infrastructure\Providers;

use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\EloquentTeamTransaction;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Queries\EloquentTeamQueries;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories\EloquentTeamCreator;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories\EloquentTeamInvitationManager;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories\EloquentTeamManager;
use Illuminate\Support\ServiceProvider;

class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreatesTeams::class, EloquentTeamCreator::class);
        $this->app->bind(ManagesTeams::class, EloquentTeamManager::class);
        $this->app->bind(ManagesTeamInvitations::class, EloquentTeamInvitationManager::class);
        $this->app->bind(RunsTeamTransactions::class, EloquentTeamTransaction::class);
        $this->app->bind(TeamQueries::class, EloquentTeamQueries::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
    }
}
