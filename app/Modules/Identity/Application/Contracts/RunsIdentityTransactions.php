<?php

namespace App\Modules\Identity\Application\Contracts;

use Closure;

interface RunsIdentityTransactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed;
}
