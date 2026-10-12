<?php

namespace App\Modules\Teams\Presentation\Http\Middleware;

use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMembership
{
    public function __construct(private TeamContext $teams) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        $user = $request->user();
        $team = $this->team($request);

        abort_if(! $user || ! $team || ! $this->teams->belongsToTeam($user->id, $team->id), 403);

        if ($minimumRole !== null) {
            $role = $this->teams->roleFor($user->id, $team->id);
            $requiredRole = TeamRole::tryFrom($minimumRole);
            abort_if($requiredRole === null || $role === null || ! $role->isAtLeast($requiredRole), 403);
        }

        $request->attributes->set('team_context_id', $team->id);
        URL::defaults(['current_team' => $team->slug, 'team' => $team->slug]);

        return $next($request);
    }

    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        return $team;
    }
}
