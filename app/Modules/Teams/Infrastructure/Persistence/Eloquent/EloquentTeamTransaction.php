<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent;

use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use Closure;
use Illuminate\Support\Facades\DB;

class EloquentTeamTransaction implements RunsTeamTransactions
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
