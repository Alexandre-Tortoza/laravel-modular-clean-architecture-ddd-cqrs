<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Modules\Identity\Application\Api\InitialAccounts;
use App\Modules\Teams\Application\Api\PersonalTeams;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class CreateFirstUser extends Command
{
    use PasswordValidationRules;

    protected $signature = 'app:create-first-user {--name= : Account name} {--email= : Account email}';

    protected $description = 'Provision the first account and personal team using a hidden, confirmed password';

    public function handle(InitialAccounts $accounts, PersonalTeams $teams): int
    {
        if ($accounts->exists()) {
            $this->error('An account already exists. First-user provisioning is unavailable.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('An interactive terminal is required to enter a hidden password.');

            return self::FAILURE;
        }

        $name = Str::trim((string) ($this->option('name') ?? $this->ask('Name')));
        $email = Str::lower(Str::trim((string) ($this->option('email') ?? $this->ask('Email'))));

        try {
            $password = $this->secret('Password', fallback: false);
            $confirmation = $this->secret('Confirm password', fallback: false);
        } catch (RuntimeException) {
            $this->error('An interactive terminal is required to enter a hidden password.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $accounts, $teams): void {
            $userId = $accounts->create($name, $email, $password);
            $teams->createFor($userId, Str::limit($name, 248, '')."'s Team");
        });

        $this->info('First user created.');

        return self::SUCCESS;
    }
}
