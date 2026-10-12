<?php

use App\Http\Responses\LoginResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\UserWithPersonalTeam;

function fortifyResponseRequest(?User $user, bool $wantsJson): Request
{
    $request = Request::create('/login', 'GET', server: $wantsJson ? ['HTTP_ACCEPT' => 'application/json'] : []);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

test('returns JSON responses for Fortify flows', function () {
    $request = fortifyResponseRequest(null, wantsJson: true);

    $login = (new LoginResponse)->toResponse($request);
    $twoFactor = (new TwoFactorLoginResponse)->toResponse($request);
    $verification = (new VerifyEmailResponse)->toResponse($request);

    expect($login->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($login->getData(true))->toBe(['two_factor' => false])
        ->and($twoFactor->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($verification->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);
});

test('redirects browser Fortify flows through the users current team', function () {
    $user = UserWithPersonalTeam::create();
    $request = fortifyResponseRequest($user, wantsJson: false);
    $teamSlug = Team::query()->findOrFail($user->current_team_id)->slug;

    $login = (new LoginResponse)->toResponse($request);
    $twoFactor = (new TwoFactorLoginResponse)->toResponse($request);
    $verification = (new VerifyEmailResponse)->toResponse($request);

    expect($login->getTargetUrl())->toContain("/{$teamSlug}")
        ->and($twoFactor->getTargetUrl())->toContain("/{$teamSlug}")
        ->and($verification->getTargetUrl())->toContain("/{$teamSlug}")
        ->and($verification->getTargetUrl())->toContain('verified=1');
});
