<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('returns the configured passkey endpoints', function () {
    $this->get(route('well-known.passkeys'))
        ->assertOk()
        ->assertJsonPath('enroll', route('security.edit'))
        ->assertJsonPath('manage', route('security.edit'));
});

test('preserves an explicitly open sidebar preference', function () {
    $request = Request::create('/');
    $request->cookies->set('sidebar_state', 'true');

    $shared = app(HandleInertiaRequests::class)->share($request);

    expect($shared['sidebarOpen'])->toBeTrue();
});

test('omits unavailable invitation context and configures Fortify rate limits', function () {
    $this->get(route('login', ['invitation' => 'missing-code']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('teamInvitation', null));

    $request = Request::create('/login', 'POST', ['email' => 'TÉST@example.com', 'credential' => ['id' => 'credential-id']], server: ['REMOTE_ADDR' => '127.0.0.1']);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('login.id', 'login-id');

    expect(RateLimiter::limiter('two-factor')($request))->toBeInstanceOf(Limit::class)
        ->and(RateLimiter::limiter('login')($request))->toBeInstanceOf(Limit::class)
        ->and(RateLimiter::limiter('passkeys')($request))->toBeInstanceOf(Limit::class);
});

test('configures production password defaults', function () {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        (new AppServiceProvider(app()))->boot();

        expect(Password::defaults())->toBeInstanceOf(Password::class);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
        (new AppServiceProvider(app()))->boot();
    }
});
