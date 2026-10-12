<?php

use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
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
