<?php

use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

it('refuses to assign the owner role through an invitation without booting Laravel', function () {
    (new InvitationPolicy)->assertCanCreate(TeamRole::Owner, TeamRole::Owner, false, [], new DateTimeImmutable);
})->throws(TeamOperationRejected::class);

it('refuses to change the owner role without booting Laravel', function () {
    (new TeamLifecyclePolicy)->assertCanChangeRole(TeamRole::Owner, TeamRole::Owner, TeamRole::Admin);
})->throws(TeamOperationRejected::class);
