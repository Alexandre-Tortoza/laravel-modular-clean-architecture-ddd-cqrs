<?php

use App\Modules\Teams\Infrastructure\Providers\TeamsServiceProvider;
use App\Modules\Profile\Infrastructure\Providers\ProfileServiceProvider;
use App\Modules\Security\Infrastructure\Providers\SecurityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TeamsServiceProvider::class,
    ProfileServiceProvider::class,
    SecurityServiceProvider::class,
];
