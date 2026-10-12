<?php

namespace App\Modules\Profile\Infrastructure\Persistence\Eloquent;

use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use Closure;
use Illuminate\Support\Facades\DB;

class EloquentProfileTransaction implements RunsProfileTransactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return DB::transaction($callback, attempts: 3);
    }
}
