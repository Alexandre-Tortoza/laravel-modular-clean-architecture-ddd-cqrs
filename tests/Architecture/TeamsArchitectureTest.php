<?php

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

arch('teams domain isolation')
    ->expect('App\\Modules\\Teams\\Domain')
    ->not->toUse([
        'App\\Modules\\Teams\\Application',
        'App\\Modules\\Teams\\Infrastructure',
        'App\\Modules\\Teams\\Presentation',
        'Illuminate',
        'Laravel',
        'Inertia',
    ]);

arch('teams application isolation')
    ->expect('App\\Modules\\Teams\\Application')
    ->not->toUse([
        'App\\Modules\\Teams\\Infrastructure',
        'App\\Modules\\Teams\\Presentation',
        'Illuminate',
        'Laravel',
        'Inertia',
    ]);

arch('profile application isolation')
    ->expect('App\\Modules\\Profile\\Application')
    ->not->toUse([
        'App\\Modules\\Profile\\Infrastructure',
        'App\\Modules\\Profile\\Presentation',
        'Illuminate',
        'Laravel',
        'Inertia',
    ]);

arch('security application isolation')
    ->expect('App\\Modules\\Security\\Application')
    ->not->toUse([
        'App\\Modules\\Security\\Infrastructure',
        'App\\Modules\\Security\\Presentation',
        'Illuminate',
        'Laravel',
        'Inertia',
    ]);

arch('teams application contracts are interfaces')
    ->expect('App\\Modules\\Teams\\Application\\Contracts')
    ->toBeInterfaces();

arch('profile and security application contracts are interfaces')
    ->expect([
        'App\\Modules\\Profile\\Application\\Contracts',
        'App\\Modules\\Security\\Application\\Contracts',
    ])
    ->toBeInterfaces();

arch('teams application data is immutable')
    ->expect('App\\Modules\\Teams\\Application\\Data')
    ->classes()
    ->toBeReadonly();

arch('migrated application commands are immutable')
    ->expect([
        'App\\Modules\\Teams\\Application\\Commands\\CreateTeam',
        'App\\Modules\\Teams\\Application\\Commands\\CreateTeamInvitation',
        'App\\Modules\\Teams\\Application\\Commands\\CancelTeamInvitation',
        'App\\Modules\\Teams\\Application\\Commands\\AcceptTeamInvitation',
        'App\\Modules\\Teams\\Application\\Commands\\DeclineTeamInvitation',
        'App\\Modules\\Teams\\Application\\Commands\\PruneExpiredTeamInvitations',
        'App\\Modules\\Teams\\Application\\Commands\\DeleteTeam',
        'App\\Modules\\Teams\\Application\\Commands\\LeaveTeam',
        'App\\Modules\\Teams\\Application\\Commands\\RemoveTeamMember',
        'App\\Modules\\Teams\\Application\\Commands\\SwitchCurrentTeam',
        'App\\Modules\\Teams\\Application\\Commands\\UpdateTeam',
        'App\\Modules\\Teams\\Application\\Commands\\UpdateTeamMemberRole',
        'App\\Modules\\Profile\\Application\\Commands\\DeleteProfile',
        'App\\Modules\\Profile\\Application\\Commands\\UpdateProfile',
        'App\\Modules\\Security\\Application\\Commands\\UpdatePassword',
    ])
    ->toBeReadonly();

arch('teams Eloquent models stay in infrastructure')
    ->expect('App\\Modules\\Teams\\Infrastructure\\Persistence\\Eloquent\\Models')
    ->classes()
    ->toExtend(Model::class);

arch('teams Eloquent factories stay in infrastructure')
    ->expect('App\\Modules\\Teams\\Infrastructure\\Persistence\\Eloquent\\Factories')
    ->classes()
    ->toExtend(Factory::class);

arch('teams application defines transaction boundaries through its contract')
    ->expect([
        'App\\Modules\\Teams\\Application\\Commands\\CreateTeamHandler',
        'App\\Modules\\Teams\\Application\\Commands\\TeamInvitationHandler',
        'App\\Modules\\Teams\\Application\\Commands\\TeamLifecycleHandler',
    ])
    ->toUse('App\\Modules\\Teams\\Application\\Contracts\\RunsTeamTransactions');

arch('teams repositories do not start transactions')
    ->expect('App\\Modules\\Teams\\Infrastructure\\Persistence\\Eloquent\\Repositories')
    ->not->toUse('Illuminate\\Support\\Facades\\DB');

arch('teams does not use profile or security internals')
    ->expect('App\\Modules\\Teams')
    ->not->toUse([
        'App\\Modules\\Profile\\Domain',
        'App\\Modules\\Profile\\Infrastructure',
        'App\\Modules\\Profile\\Presentation',
        'App\\Modules\\Security\\Domain',
        'App\\Modules\\Security\\Infrastructure',
        'App\\Modules\\Security\\Presentation',
    ]);

arch('profile and security do not use teams internals')
    ->expect([
        'App\\Modules\\Profile',
        'App\\Modules\\Security',
    ])
    ->not->toUse([
        'App\\Modules\\Teams\\Domain',
        'App\\Modules\\Teams\\Infrastructure',
        'App\\Modules\\Teams\\Presentation',
    ]);

arch('teams production code has no debug helpers')
    ->expect('App\\Modules\\Teams')
    ->not->toUse(['dd', 'dump', 'ray']);

arch('profile and security production code has no debug helpers')
    ->expect([
        'App\\Modules\\Profile',
        'App\\Modules\\Security',
    ])
    ->not->toUse(['dd', 'dump', 'ray']);
