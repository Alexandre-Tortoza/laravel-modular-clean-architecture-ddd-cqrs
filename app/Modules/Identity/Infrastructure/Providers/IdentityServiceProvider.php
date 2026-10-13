<?php

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Application\Api\AccountCredentials;
use App\Modules\Identity\Application\Api\InitialAccounts;
use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Application\Api\SecurityAccounts;
use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Application\Contracts\RunsIdentityTransactions;
use App\Modules\Identity\Application\Contracts\StoresProfileAccounts;
use App\Modules\Identity\Application\Services\OwnProfileAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentAccountCredentials;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentIdentityTransaction;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentInitialAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentProfileAccountStore;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentTeamSelectionAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Queries\EloquentSecurityAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Queries\EloquentUserDirectory;
use App\Modules\Identity\Presentation\Fortify\ResetUserPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->bind(InitialAccounts::class, EloquentInitialAccounts::class);
        $this->app->bind(TeamSelectionAccounts::class, EloquentTeamSelectionAccounts::class);
        $this->app->bind(ProfileAccounts::class, OwnProfileAccounts::class);
        $this->app->bind(StoresProfileAccounts::class, EloquentProfileAccountStore::class);
        $this->app->bind(RunsIdentityTransactions::class, EloquentIdentityTransaction::class);
        $this->app->bind(AccountCredentials::class, EloquentAccountCredentials::class);
        $this->app->bind(SecurityAccounts::class, EloquentSecurityAccounts::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
