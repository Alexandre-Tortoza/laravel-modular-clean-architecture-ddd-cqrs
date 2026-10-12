<?php

namespace App\Modules\Teams\Infrastructure\Providers;

use App\Modules\Teams\Application\Api\AccountTeamLifecycle;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\PruneExpiredTeamInvitations;
use App\Modules\Teams\Application\Commands\PruneExpiredTeamInvitationsHandler;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Contracts\ManagesAccountTeams;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\SendsTeamInvitations;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Services\AccountTeamLifecycleService;
use App\Modules\Teams\Infrastructure\Clock\SystemTeamClock;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitationSender;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\EloquentTeamTransaction;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Queries\EloquentTeamQueries;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories\EloquentTeamCreator;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories\EloquentTeamInvitationManager;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories\EloquentTeamManager;
use App\Modules\Teams\Presentation\Policies\TeamPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class TeamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreatesTeams::class, EloquentTeamCreator::class);
        $this->app->bind(ManagesTeams::class, EloquentTeamManager::class);
        $this->app->bind(ManagesAccountTeams::class, EloquentTeamManager::class);
        $this->app->bind(AccountTeamLifecycle::class, AccountTeamLifecycleService::class);
        $this->app->bind(ManagesTeamInvitations::class, EloquentTeamInvitationManager::class);
        $this->app->bind(RunsTeamTransactions::class, EloquentTeamTransaction::class);
        $this->app->bind(TeamQueries::class, EloquentTeamQueries::class);
        $this->app->bind(TeamContext::class, EloquentTeamQueries::class);
        $this->app->bind(TeamClock::class, SystemTeamClock::class);
        $this->app->bind(SendsTeamInvitations::class, TeamInvitationSender::class);
    }

    public function boot(): void
    {
        Gate::policy(Team::class, TeamPolicy::class);
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        Schedule::call(function (PruneExpiredTeamInvitationsHandler $handler): void {
            $handler->handle(new PruneExpiredTeamInvitations);
        })->daily()->description('Delete expired team invitations');
    }
}
