<?php

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
        'Illuminate',
        'Laravel',
    ]);

arch('security application isolation')
    ->expect('App\\Modules\\Security\\Application')
    ->not->toUse([
        'App\\Modules\\Security\\Infrastructure',
        'Illuminate',
        'Laravel',
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

arch('teams production code has no debug helpers')
    ->expect('App\\Modules\\Teams')
    ->not->toUse(['dd', 'dump', 'ray']);

arch('profile and security production code has no debug helpers')
    ->expect([
        'App\\Modules\\Profile',
        'App\\Modules\\Security',
    ])
    ->not->toUse(['dd', 'dump', 'ray']);
