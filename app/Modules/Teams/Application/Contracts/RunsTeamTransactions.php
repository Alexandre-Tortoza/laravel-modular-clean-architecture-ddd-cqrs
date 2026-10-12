<?php

namespace App\Modules\Teams\Application\Contracts;

use Closure;

interface RunsTeamTransactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed;
}
