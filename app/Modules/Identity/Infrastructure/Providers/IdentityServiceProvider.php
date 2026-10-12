<?php

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Application\Api\AccountCredentials;
use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Application\Api\SecurityAccounts;
use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentAccountCredentials;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentProfileAccounts;
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
        $this->app->bind(TeamSelectionAccounts::class, EloquentTeamSelectionAccounts::class);
        $this->app->bind(ProfileAccounts::class, EloquentProfileAccounts::class);
        $this->app->bind(AccountCredentials::class, EloquentAccountCredentials::class);
        $this->app->bind(SecurityAccounts::class, EloquentSecurityAccounts::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
