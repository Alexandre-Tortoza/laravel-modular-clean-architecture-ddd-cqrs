<?php

namespace App\Modules\Teams\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Teams\Application\Commands\RemoveTeamMember;
use App\Modules\Teams\Application\Commands\TeamLifecycleHandler;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRole;
use App\Modules\Teams\Application\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Enums\TeamRole;
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
    public function update(UpdateTeamMemberRequest $request, Team $team, User $user, TeamLifecycleHandler $lifecycle): RedirectResponse
    {
        Gate::authorize('updateMember', $team);

        $newRole = TeamRole::from($request->validated('role'));

        try {
            $lifecycle->updateMemberRole(new UpdateTeamMemberRole($request->user()->id, $team->id, $user->id, $newRole));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Request $request, Team $team, User $user, TeamLifecycleHandler $lifecycle): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($user), 403, __('The team owner cannot be removed.'));

        try {
            $lifecycle->removeMember(new RemoveTeamMember($request->user()->id, $team->id, $user->id));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
