<?php

use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;

test('returns labels, hierarchy, and assignable roles', function () {
    expect(TeamRole::Owner->label())->toBe('Owner')
        ->and(TeamRole::Admin->level())->toBe(2)
        ->and(TeamRole::Owner->isAtLeast(TeamRole::Admin))->toBeTrue()
        ->and(TeamRole::Member->isAtLeast(TeamRole::Admin))->toBeFalse()
        ->and(TeamRole::assignable())->toBe([
            ['value' => 'admin', 'label' => 'Admin'],
            ['value' => 'member', 'label' => 'Member'],
        ]);
});

test('maps permissions to each role', function () {
    expect(TeamRole::Owner->permissions())->toEqual(TeamPermission::cases())
        ->and(TeamRole::Admin->permissions())->toBe([
            TeamPermission::UpdateTeam,
            TeamPermission::CreateInvitation,
            TeamPermission::CancelInvitation,
        ])
        ->and(TeamRole::Member->permissions())->toBe([])
        ->and(TeamRole::Admin->hasPermission(TeamPermission::UpdateTeam))->toBeTrue()
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteTeam))->toBeFalse();
});

test('evaluates each enum case through its match expressions', function (TeamRole $role) {
    expect($role->permissions())->toBeArray()
        ->and($role->level())->toBeInt()
        ->and($role->hasPermission(TeamPermission::UpdateTeam))->toBeBool();
})->with(TeamRole::cases());

test('evaluates the owner match branches', function () {
    expect(TeamRole::Owner->permissions())->toEqual(TeamPermission::cases())
        ->and(TeamRole::Owner->level())->toBe(3)
        ->and(TeamRole::Owner->hasPermission(TeamPermission::DeleteTeam))->toBeTrue();
});

test('evaluates the admin match branches', function () {
    expect(TeamRole::Admin->permissions())->toContain(TeamPermission::UpdateTeam)
        ->and(TeamRole::Admin->level())->toBe(2)
        ->and(TeamRole::Admin->hasPermission(TeamPermission::DeleteTeam))->toBeFalse();
});

test('evaluates the member match branches', function () {
    expect(TeamRole::Member->permissions())->toBe([])
        ->and(TeamRole::Member->level())->toBe(1)
        ->and(TeamRole::Member->hasPermission(TeamPermission::UpdateTeam))->toBeFalse();
});
