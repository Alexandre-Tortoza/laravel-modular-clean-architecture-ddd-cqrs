<?php

namespace App\Http\Responses\Concerns;

use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Data\TeamData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $team = $this->currentTeam($request);

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    protected function currentTeam(Request $request): TeamData
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = app(TeamQueries::class)->currentForUser($user->id);

        abort_if(! $team, 403);

        return $team;
    }
}
