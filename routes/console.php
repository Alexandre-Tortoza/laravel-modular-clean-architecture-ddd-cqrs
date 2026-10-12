<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

Artisan::command('app:ensure-key', function (): int {
    $configuration = config();

    if (filled($configuration->get('app.key'))) {
        $this->components->info('Existing application key preserved.');

        return Command::SUCCESS;
    }

    $exitCode = $this->call('key:generate', ['--no-interaction' => true]);

    return $exitCode === Command::SUCCESS && $configuration->string('app.key') !== ''
        ? Command::SUCCESS
        : Command::FAILURE;
})->purpose('Generate an application key only when one is missing');

// Module providers register their own scheduled operations.
