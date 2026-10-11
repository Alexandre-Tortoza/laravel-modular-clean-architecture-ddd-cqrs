<?php

use App\Modules\Teams\Infrastructure\Providers\TeamsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TeamsServiceProvider::class,
];
