<?php

namespace App\Http\Middleware;

use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Data\TeamData;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private TeamQueries $teams) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user ? $this->userTeam($this->teams->currentForUser($user->id)) : null,
            'teams' => fn () => $user ? collect($this->teams->forUser($user->id, includeCurrent: true))->map($this->userTeam(...)) : [],
        ];
    }

    /** @return array{id: int, name: string, slug: string, isPersonal: bool, role: ?string, roleLabel: ?string, isCurrent: bool}|null */
    private function userTeam(?TeamData $team): ?array
    {
        return $team ? ['id' => $team->id, 'name' => $team->name, 'slug' => $team->slug, 'isPersonal' => $team->isPersonal, 'role' => $team->role?->value, 'roleLabel' => $team->role?->label(), 'isCurrent' => $team->isCurrent] : null;
    }
}
