<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\DeleteTeamHandler;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Policies\TeamPolicy;
use Tests\Support\UserWithPersonalTeam;

test('allows viewing only teams a user belongs to', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $policy = app(TeamPolicy::class);

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->view($user, $team))->toBeTrue()
        ->and($policy->view(UserWithPersonalTeam::create(), $team))->toBeFalse();
});

test('domain, HTTP policy, projection, and delete command agree on effective capabilities', function (bool $personal, ?TeamRole $role, bool $canDelete, bool $canLeave) {
    $user = User::factory()->create();
    $team = Team::factory()->create(['is_personal' => $personal]);

    if ($role !== null) {
        $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
    }

    $domain = new TeamLifecyclePolicy;
    $policy = app(TeamPolicy::class);

    expect($domain->canDelete($personal, $role))->toBe($canDelete)
        ->and($domain->canLeave($personal, $role))->toBe($canLeave)
        ->and($policy->delete($user, $team))->toBe($canDelete)
        ->and($policy->leave($user, $team))->toBe($canLeave)
        ->and(app(TeamQueries::class)->permissionsFor($user->id, $team->id)->canDeleteTeam)->toBe($canDelete);

    if ($canDelete) {
        app(DeleteTeamHandler::class)->handle(new DeleteTeam($user->id, $team->id));
        $this->assertSoftDeleted('teams', ['id' => $team->id]);
    } else {
        expect(fn () => app(DeleteTeamHandler::class)->handle(new DeleteTeam($user->id, $team->id)))
            ->toThrow(TeamOperationRejected::class);
        $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
    }
})->with([
    'personal owner' => [true, TeamRole::Owner, false, false],
    'shared owner' => [false, TeamRole::Owner, true, false],
    'shared admin' => [false, TeamRole::Admin, false, true],
    'shared member' => [false, TeamRole::Member, false, true],
    'shared outsider' => [false, null, false, false],
]);

test('allows actions only for roles with the required permission', function () {
    $team = Team::factory()->create();
    $owner = UserWithPersonalTeam::create();
    $admin = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $policy = app(TeamPolicy::class);

    foreach (TeamPermission::cases() as $permission) {
        expect(app(TeamContext::class)->hasPermission($owner->id, $team->id, $permission))->toBeTrue();
    }

    expect($policy->update($admin, $team))->toBeTrue()
        ->and($policy->inviteMember($admin, $team))->toBeTrue()
        ->and($policy->cancelInvitation($admin, $team))->toBeTrue()
        ->and($policy->addMember($admin, $team))->toBeFalse()
        ->and($policy->updateMember($member, $team))->toBeFalse()
        ->and($policy->removeMember($member, $team))->toBeFalse()
        ->and($policy->delete($member, $team))->toBeFalse();
});

test('allows only non-owner members to leave non-personal teams', function () {
    $policy = app(TeamPolicy::class);
    $team = Team::factory()->create();
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $outsider = UserWithPersonalTeam::create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect($policy->leave($member, $team))->toBeTrue()
        ->and($policy->leave($owner, $team))->toBeFalse()
        ->and($policy->leave($outsider, $team))->toBeFalse()
        ->and($policy->leave($member, Team::factory()->personal()->create()))->toBeFalse()
        ->and($policy->delete($owner, $team))->toBeTrue()
        ->and($policy->delete($owner, Team::factory()->personal()->create()))->toBeFalse();
});
