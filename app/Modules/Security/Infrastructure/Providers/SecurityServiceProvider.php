<?php

namespace App\Modules\Security\Infrastructure\Providers;

use App\Modules\Security\Application\Contracts\UpdatesPasswords;
use App\Modules\Security\Infrastructure\Persistence\Eloquent\EloquentPasswordUpdater;
use Illuminate\Support\ServiceProvider;

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UpdatesPasswords::class, EloquentPasswordUpdater::class);
    }
}
