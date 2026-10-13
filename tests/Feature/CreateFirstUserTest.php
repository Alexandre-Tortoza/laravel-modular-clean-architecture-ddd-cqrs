<?php

use App\Console\Commands\CreateFirstUser;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Api\PersonalTeams;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('the first account is provisioned with a hidden confirmed password and personal team', function () {
    $this->artisan('app:create-first-user')
        ->expectsQuestion('Name', 'Template Owner')
        ->expectsQuestion('Email', '  OWNER@EXAMPLE.COM  ')
        ->expectsQuestion('Password', 'SecretPass!42')
        ->expectsQuestion('Confirm password', 'SecretPass!42')
        ->doesntExpectOutputToContain('SecretPass!42')
        ->expectsOutput('First user created.')
        ->assertSuccessful();

    $user = User::query()->sole();
    expect($user->email)->toBe('owner@example.com')
        ->and($user->name)->toBe('Template Owner')
        ->and(Hash::check('SecretPass!42', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();
    $team = Team::query()->sole();
    expect($team->is_personal)->toBeTrue()
        ->and($user->current_team_id)->toBe($team->id);
    $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'owner']);
});

test('noninteractive provisioning fails without creating accounts or leaking credentials', function () {
    $this->artisan('app:create-first-user', ['--name' => 'Owner', '--email' => 'owner@example.com', '--no-interaction' => true])
        ->expectsOutput('An interactive terminal is required to enter a hidden password.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

test('existing accounts disable bootstrap without changing credentials or creating teams', function (string $email) {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $hash = $user->password;

    $this->artisan('app:create-first-user', ['--name' => 'Owner', '--email' => $email])
        ->expectsOutput('An account already exists. First-user provisioning is unavailable.')
        ->assertFailed();

    expect($user->refresh()->password)->toBe($hash);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('teams', 0);
})->with(['owner@example.com', 'other@example.com']);

test('invalid bootstrap input creates neither a user nor a team', function (string $name, string $email, string $password, string $confirmation, string $message) {
    $this->artisan('app:create-first-user', ['--name' => $name, '--email' => $email])
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->expectsOutput($message)
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
})->with([
    'empty name' => ['', 'owner@example.com', 'SecretPass!42', 'SecretPass!42', 'The name field is required.'],
    'long name' => [str_repeat('a', 256), 'owner@example.com', 'SecretPass!42', 'SecretPass!42', 'The name field must not be greater than 255 characters.'],
    'empty email' => ['Owner', '', 'SecretPass!42', 'SecretPass!42', 'The email field is required.'],
    'invalid email' => ['Owner', 'invalid', 'SecretPass!42', 'SecretPass!42', 'The email field must be a valid email address.'],
    'empty password' => ['Owner', 'owner@example.com', '', '', 'The password field is required.'],
    'short password' => ['Owner', 'owner@example.com', 'short', 'short', 'The password field must be at least 8 characters.'],
    'confirmation mismatch' => ['Owner', 'owner@example.com', 'SecretPass!42', 'Different!42', 'The password field confirmation does not match.'],
]);

test('non-sensitive options support unicode names up to the Identity limit', function () {
    $name = str_repeat('é', 255);

    $this->artisan('app:create-first-user', ['--name' => $name, '--email' => 'OWNER@EXAMPLE.COM'])
        ->expectsQuestion('Password', 'SecretPass!42')
        ->expectsQuestion('Confirm password', 'SecretPass!42')
        ->assertSuccessful();

    expect(User::query()->sole()->name)->toBe($name);
    expect(mb_strlen(Team::query()->sole()->name))->toBe(255);
});

test('provisioning uses the configured password security policy', function () {
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

    try {
        $this->artisan('app:create-first-user', ['--name' => 'Owner', '--email' => 'owner@example.com'])
            ->expectsQuestion('Password', 'long-but-weak-password')
            ->expectsQuestion('Confirm password', 'long-but-weak-password')
            ->expectsOutputToContain('The password field must contain at least one uppercase and one lowercase letter.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('teams', 0);
    } finally {
        Password::defaults(fn () => null);
    }
});

test('a terminal that cannot hide input fails without writing accounts or echoing its exception', function () {
    $command = Mockery::mock(CreateFirstUser::class)->makePartial();
    $command->__construct();
    $command->shouldReceive('secret')->once()->with('Password', false)
        ->andThrow(new RuntimeException('sensitive terminal detail'));
    $command->setLaravel($this->app);
    $input = new ArrayInput(['--name' => 'Owner', '--email' => 'owner@example.com'], $command->getDefinition());
    $input->setInteractive(true);
    $output = new BufferedOutput;

    expect($command->run($input, $output))->toBe(1);
    expect($output->fetch())->toContain('An interactive terminal is required to enter a hidden password.')
        ->not->toContain('sensitive terminal detail');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

test('a personal-team failure rolls back the newly provisioned account', function () {
    $this->mock(PersonalTeams::class, function (MockInterface $mock): void {
        $mock->shouldReceive('createFor')->once()->andThrow(new RuntimeException('team creation failed'));
    });

    expect(fn () => $this->artisan('app:create-first-user', ['--name' => 'Owner', '--email' => 'owner@example.com'])
        ->expectsQuestion('Password', 'SecretPass!42')
        ->expectsQuestion('Confirm password', 'SecretPass!42')
        ->run())->toThrow(RuntimeException::class, 'team creation failed');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

test('an empty interactive name is rejected without a type error', function () {
    $this->artisan('app:create-first-user')
        ->expectsQuestion('Name', null)
        ->expectsQuestion('Email', 'owner@example.com')
        ->expectsQuestion('Password', 'SecretPass!42')
        ->expectsQuestion('Confirm password', 'SecretPass!42')
        ->expectsOutput('The name field is required.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});
