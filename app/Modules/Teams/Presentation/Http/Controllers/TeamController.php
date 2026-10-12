<?php

namespace App\Modules\Teams\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\DeleteTeamHandler;
use App\Modules\Teams\Application\Commands\LeaveTeam;
use App\Modules\Teams\Application\Commands\LeaveTeamHandler;
use App\Modules\Teams\Application\Commands\SwitchCurrentTeam;
use App\Modules\Teams\Application\Commands\SwitchCurrentTeamHandler;
use App\Modules\Teams\Application\Commands\UpdateTeam;
use App\Modules\Teams\Application\Commands\UpdateTeamHandler;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\InvalidTeamName;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Http\Requests\DeleteTeamRequest;
use App\Modules\Teams\Presentation\Http\Requests\SaveTeamRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(Request $request, TeamQueries $queries): Response
    {
        return Inertia::render('teams/Index', [
            'teams' => collect($queries->forUser($request->user()->id, includeCurrent: true))
                ->map(fn ($team): array => [
                    'id' => $team->id, 'name' => $team->name, 'slug' => $team->slug,
                    'isPersonal' => $team->isPersonal, 'role' => $team->role?->value,
                    'roleLabel' => $team->role?->label(), 'isCurrent' => $team->isCurrent,
                ]),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeamHandler $createTeam): RedirectResponse
    {
        try {
            $team = $createTeam->handle(new CreateTeam(
                userId: $request->user()->id,
                name: $request->validated('name'),
            ));
        } catch (InvalidTeamName $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     */
    public function edit(Request $request, Team $team, TeamQueries $queries): Response
    {
        try {
            $edit = $queries->editFor($request->user()->id, $team->id);
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        return Inertia::render('teams/Edit', [
            'team' => [
                'id' => $edit->team->id, 'name' => $edit->team->name,
                'slug' => $edit->team->slug, 'isPersonal' => $edit->team->isPersonal,
            ],
            'members' => $edit->members, 'invitations' => $edit->invitations,
            'permissions' => $edit->permissions,
            'availableRoles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team, UpdateTeamHandler $handler): RedirectResponse
    {
        Gate::authorize('update', $team);

        try {
            $team = $handler->handle(new UpdateTeam($request->user()->id, $team->id, $request->validated('name')));
        } catch (InvalidTeamName $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Request $request, Team $team, SwitchCurrentTeamHandler $handler, TeamContext $context): RedirectResponse
    {
        abort_unless($context->belongsToTeam($request->user()->id, $team->id), 403);

        try {
            $handler->handle(new SwitchCurrentTeam($request->user()->id, $team->id));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        return back();
    }

    /**
     * Leave the specified team.
     */
    public function leave(Request $request, Team $team, LeaveTeamHandler $handler): RedirectResponse
    {
        Gate::authorize('leave', $team);

        try {
            $handler->handle(new LeaveTeam($request->user()->id, $team->id));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the team ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team, DeleteTeamHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteTeam($request->user()->id, $team->id));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('teams.index');
    }
}
