<?php

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Application\Contracts\DeletesProfiles;
use App\Modules\Identity\Application\Contracts\RunsProfileTransactions;
use App\Modules\Identity\Application\Contracts\UpdatesPasswords;
use App\Modules\Identity\Application\Contracts\UpdatesProfiles;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentPasswordUpdater;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentProfileDeleter;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentProfileTransaction;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\EloquentProfileUpdater;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Queries\EloquentUserDirectory;
use App\Modules\Identity\Presentation\Fortify\ResetUserPassword;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->bind(DeletesProfiles::class, EloquentProfileDeleter::class);
        $this->app->bind(RunsProfileTransactions::class, EloquentProfileTransaction::class);
        $this->app->bind(UpdatesProfiles::class, EloquentProfileUpdater::class);
        $this->app->bind(UpdatesPasswords::class, EloquentPasswordUpdater::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/../../routes/settings.php');
        }
    }
}
