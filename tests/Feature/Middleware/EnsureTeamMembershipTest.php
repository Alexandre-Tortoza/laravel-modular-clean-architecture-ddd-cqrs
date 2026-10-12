<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\UserWithPersonalTeam;

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

test('allows a member to use a team resolved from its slug without persisting selection', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $response = app(EnsureTeamMembership::class)->handle(
        membershipRequest($user, 'current_team', $team->slug),
        fn (): Response => new Response('next'),
    );

    expect($response->getContent())->toBe('next')
        ->and($user->fresh()->current_team_id)->not->toBe($team->id)
        ->and(app(TeamContext::class)->currentForUser($user->id)->id)->toBe($user->current_team_id)
        ->and(URL::getDefaultParameters())->toMatchArray([
            'current_team' => $team->slug,
            'team' => $team->slug,
        ]);
});

test('allows a sufficient role without changing the current team for a team route parameter', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

    $response = app(EnsureTeamMembership::class)->handle(
        membershipRequest($user, 'team', $team),
        fn (): Response => new Response('next'),
        TeamRole::Member->value,
    );

    expect($response->getContent())->toBe('next')
        ->and($user->fresh()->current_team_id)->not->toBe($team->id);
});

test('does not invoke the persistent switch command for a current team route', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $request = membershipRequest($user, 'current_team', $team);

    $response = app(EnsureTeamMembership::class)->handle(
        $request,
        fn (): Response => new Response('next'),
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
            membershipRequest(UserWithPersonalTeam::create(), 'team', null),
            fn (): Response => new Response('next'),
        ))->toThrow(HttpException::class)
        ->and(fn () => $middleware->handle(
            membershipRequest(UserWithPersonalTeam::create(), 'team', 'missing-team'),
            fn (): Response => new Response('next'),
        ))->toThrow(HttpException::class)
        ->and(fn () => $middleware->handle(
            membershipRequest(UserWithPersonalTeam::create(), 'team', Team::factory()->create()),
            fn (): Response => new Response('next'),
        ))->toThrow(HttpException::class);
});

test('forbids invalid and insufficient minimum roles', function (string $minimumRole) {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    expect(fn () => app(EnsureTeamMembership::class)->handle(
        membershipRequest($user, 'team', $team),
        fn (): Response => new Response('next'),
        $minimumRole,
    ))->toThrow(HttpException::class);
})->with([
    'unknown role' => 'editor',
    'insufficient role' => TeamRole::Admin->value,
]);
