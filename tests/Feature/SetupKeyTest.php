<?php

test('setup generates a missing application key and preserves it on rerun', function () {
    $environmentDirectory = sys_get_temp_dir().'/setup-key-'.bin2hex(random_bytes(8));
    mkdir($environmentDirectory);
    $environmentFile = $environmentDirectory.'/.env';
    file_put_contents($environmentFile, "APP_KEY=\n");
    $this->app->useEnvironmentPath($environmentDirectory);
    config(['app.key' => '']);

    try {
        $this->artisan('app:ensure-key')->assertSuccessful();
        $generatedKey = config('app.key');
        $generatedEnvironment = file_get_contents($environmentFile);

        expect($generatedKey)->toStartWith('base64:');
        expect(strlen(base64_decode(substr($generatedKey, 7), true)))->toBe(32);

        $this->artisan('app:ensure-key')->assertSuccessful();

        expect(config('app.key'))->toBe($generatedKey);
        expect(file_get_contents($environmentFile))->toBe($generatedEnvironment);
    } finally {
        unlink($environmentFile);
        rmdir($environmentDirectory);
    }
});

test('setup preserves an existing application key without changing the environment file', function (string $environmentContents) {
    $environmentDirectory = sys_get_temp_dir().'/setup-key-'.bin2hex(random_bytes(8));
    mkdir($environmentDirectory);
    $environmentFile = $environmentDirectory.'/.env';
    file_put_contents($environmentFile, $environmentContents);
    $this->app->useEnvironmentPath($environmentDirectory);
    $existingKey = 'base64:'.base64_encode(str_repeat('a', 32));
    config(['app.key' => $existingKey]);

    try {
        $this->artisan('app:ensure-key')->assertSuccessful();

        expect(config('app.key'))->toBe($existingKey);
        expect(file_get_contents($environmentFile))->toBe($environmentContents);
    } finally {
        unlink($environmentFile);
        rmdir($environmentDirectory);
    }
})->with([
    'key in dotenv' => "APP_KEY=\"base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=\"\n",
    'key supplied by the process environment' => "APP_KEY=\n",
]);

test('setup fails when the environment file has no application key entry', function () {
    $environmentDirectory = sys_get_temp_dir().'/setup-key-'.bin2hex(random_bytes(8));
    mkdir($environmentDirectory);
    $environmentFile = $environmentDirectory.'/.env';
    file_put_contents($environmentFile, "APP_NAME=Laravel\n");
    $this->app->useEnvironmentPath($environmentDirectory);
    config(['app.key' => '']);

    try {
        $this->artisan('app:ensure-key')->assertFailed();

        expect(file_get_contents($environmentFile))->toBe("APP_NAME=Laravel\n");
    } finally {
        unlink($environmentFile);
        rmdir($environmentDirectory);
    }
});
