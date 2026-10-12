<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Contracts\RunsIdentityTransactions;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

class EloquentIdentityTransaction implements RunsIdentityTransactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return DB::connection((new User)->getConnectionName())->transaction($callback, attempts: 3);
    }
}
