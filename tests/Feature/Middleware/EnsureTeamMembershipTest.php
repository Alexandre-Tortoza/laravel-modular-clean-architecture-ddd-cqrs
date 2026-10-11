<?php

use App\Models\User;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

function membershipRequest(?User $user, string $parameter, Team|string|null $team): Request
{
    $route = new class($parameter, $team)
    {
        public function __construct(private string $parameter, private Team|string|null $team) {}

        public function parameter(string $parameter): Team|string|null
        {
            return $parameter === $this->parameter ? $this->team : null;
        }
    };

    $request = Request::create('/');
    $request->setUserResolver(fn (): ?User => $user);
    $request->setRouteResolver(fn (): object => $route);

    return $request;
}

test('allows a member and switches to a team resolved from its slug', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $response = app(EnsureTeamMembership::class)->handle(
        membershipRequest($user, 'current_team', $team->slug),
        fn (): Response => new Response('next'),
    );

    expect($response->getContent())->toBe('next')
        ->and($user->fresh()->current_team_id)->toBe($team->id)
        ->and($user->current_team_id)->toBe($team->id)
        ->and($user->currentTeam->id)->toBe($team->id)
        ->and(URL::getDefaultParameters()['current_team'])->toBe($team->slug);
});

test('allows a sufficient role without changing the current team for a team route parameter', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $response = app(EnsureTeamMembership::class)->handle(
        membershipRequest($user, 'team', $team),
        fn (): Response => new Response('next'),
        TeamRole::Member->value,
    );

    expect($response->getContent())->toBe('next')
        ->and($user->fresh()->current_team_id)->not->toBe($team->id);
});

test('forbids missing users, teams, and memberships', function () {
    $middleware = app(EnsureTeamMembership::class);

    expect(fn () => $middleware->handle(
        membershipRequest(null, 'team', Team::factory()->create()),
        fn (): Response => new Response('next'),
    ))->toThrow(HttpException::class)
        ->and(fn () => $middleware->handle(
            membershipRequest(User::factory()->create(), 'team', null),
            fn (): Response => new Response('next'),
        ))->toThrow(HttpException::class)
        ->and(fn () => $middleware->handle(
            membershipRequest(User::factory()->create(), 'team', 'missing-team'),
            fn (): Response => new Response('next'),
        ))->toThrow(HttpException::class)
        ->and(fn () => $middleware->handle(
            membershipRequest(User::factory()->create(), 'team', Team::factory()->create()),
            fn (): Response => new Response('next'),
        ))->toThrow(HttpException::class);
});

test('forbids invalid and insufficient minimum roles', function (string $minimumRole) {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    expect(fn () => app(EnsureTeamMembership::class)->handle(
        membershipRequest($user, 'team', $team),
        fn (): Response => new Response('next'),
        $minimumRole,
    ))->toThrow(HttpException::class);
})->with([
    'unknown role' => 'editor',
    'insufficient role' => TeamRole::Admin->value,
]);
