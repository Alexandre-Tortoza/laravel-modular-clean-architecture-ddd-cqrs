<?php

use Illuminate\Contracts\Console\Kernel;

function failProductionRuntime(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}

try {
    $root = dirname(__DIR__, 2);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $configurationCache = $app->getCachedConfigPath();
    if (is_file($configurationCache) && ! unlink($configurationCache)) {
        failProductionRuntime('Cannot replace the private runtime configuration cache.');
    }

    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $configuration = $app->make('config');
    if (! $app->isProduction() || $configuration->get('app.debug') !== false) {
        failProductionRuntime('Production runtime requires APP_ENV=production and APP_DEBUG=false.');
    }

    if (! is_string($configuration->get('app.key')) || $configuration->get('app.key') === '') {
        failProductionRuntime('Inject a stable APP_KEY before starting the production runtime.');
    }
    $app->make('encrypter');

    $url = parse_url($configuration->get('app.url'));
    if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])) {
        failProductionRuntime('Production runtime requires an HTTPS APP_URL.');
    }

    if ($configuration->get('session.secure') !== true) {
        failProductionRuntime('Production runtime requires secure session cookies.');
    }

    foreach ($configuration->get('trustedproxy.proxies') as $proxy) {
        $address = explode('/', $proxy, 2);
        $validAddress = filter_var($address[0], FILTER_VALIDATE_IP) !== false;
        $validPrefix = ! isset($address[1]) || (ctype_digit($address[1]) && (int) $address[1] > 0 && (int) $address[1] <= (str_contains($address[0], ':') ? 128 : 32));
        if (! $validAddress || ! $validPrefix) {
            failProductionRuntime('TRUSTED_PROXIES must list explicit IP addresses or bounded CIDRs.');
        }
    }

    if ($kernel->call('config:cache', ['--no-interaction' => true]) !== 0 || $kernel->call('route:cache', ['--no-interaction' => true]) !== 0) {
        failProductionRuntime('Production configuration and route cache preparation failed.');
    }
} catch (Throwable) {
    failProductionRuntime('Production runtime preparation failed. Verify injected configuration and private cache permissions.');
}
