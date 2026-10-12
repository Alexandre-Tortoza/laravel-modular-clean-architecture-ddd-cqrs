<?php

namespace App\Modules\Teams\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Commands\RemoveTeamMember;
use App\Modules\Teams\Application\Commands\RemoveTeamMemberHandler;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRole;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRoleHandler;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Http\Requests\UpdateTeamMemberRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Update the specified team member's role.
     */
    public function update(UpdateTeamMemberRequest $request, Team $team, string $user, UpdateTeamMemberRoleHandler $handler, UserDirectory $users): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        abort_if(! ctype_digit($user) || $users->find((int) $user) === null, 404);

        $newRole = TeamRole::from($request->validated('role'));

        try {
            $handler->handle(new UpdateTeamMemberRole($request->user()->id, $team->id, (int) $user, $newRole));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Request $request, Team $team, string $user, RemoveTeamMemberHandler $handler, UserDirectory $users): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if(! ctype_digit($user) || $users->find((int) $user) === null, 404);

        try {
            $handler->handle(new RemoveTeamMember($request->user()->id, $team->id, (int) $user));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
