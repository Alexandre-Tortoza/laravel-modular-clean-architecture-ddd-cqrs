<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Seeders;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use Illuminate\Database\Seeder;

class DemoPersonalTeamSeeder extends Seeder
{
    public function run(): void
    {
        $user = app(UserDirectory::class)->findByEmail('test@example.com');

        if ($user === null) {
            return;
        }

        foreach (app(TeamContext::class)->forUser($user->id, includeCurrent: true) as $team) {
            if ($team->isPersonal) {
                return;
            }
        }

        app(CreateTeamHandler::class)->handle(new CreateTeam($user->id, "{$user->name}'s Team", isPersonal: true));
    }
}
