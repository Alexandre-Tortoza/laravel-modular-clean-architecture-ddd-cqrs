<?php

namespace App\Modules\Profile\Infrastructure\Providers;

use App\Modules\Profile\Application\Contracts\DeletesProfiles;
use App\Modules\Profile\Application\Contracts\UpdatesProfiles;
use App\Modules\Profile\Infrastructure\Persistence\Eloquent\EloquentProfileDeleter;
use App\Modules\Profile\Infrastructure\Persistence\Eloquent\EloquentProfileUpdater;
use Illuminate\Support\ServiceProvider;

class ProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DeletesProfiles::class, EloquentProfileDeleter::class);
        $this->app->bind(UpdatesProfiles::class, EloquentProfileUpdater::class);
    }
}
