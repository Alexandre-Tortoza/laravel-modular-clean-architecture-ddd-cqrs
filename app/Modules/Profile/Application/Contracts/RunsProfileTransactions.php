<?php

namespace App\Modules\Profile\Application\Contracts;

use Closure;

interface RunsProfileTransactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed;
}
