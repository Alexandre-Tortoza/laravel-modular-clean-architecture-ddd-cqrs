<?php

namespace App\Modules\Profile\Infrastructure\Providers;

use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Profile\Infrastructure\Persistence\Eloquent\EloquentProfileTransaction;
use Illuminate\Support\ServiceProvider;

class ProfileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RunsProfileTransactions::class, EloquentProfileTransaction::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
    }
}
