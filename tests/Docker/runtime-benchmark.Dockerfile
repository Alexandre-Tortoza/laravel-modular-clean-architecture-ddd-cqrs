ARG PHP_IMAGE=benchmark-source-php
ARG NGINX_IMAGE=benchmark-nginx
FROM ${PHP_IMAGE} AS fpm
USER root
COPY tests/Docker/runtime-benchmark.fpm.conf /usr/local/etc/php-fpm.d/zzz-benchmark-workers.conf
USER www-data:www-data

FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer-binary
FROM dunglas/frankenphp:1.12.7-php8.5.10-bookworm@sha256:59d92e5b7616cdabf6cd9817575ed0555024bf73ac398a57df1320306bc23dd2 AS octane
RUN install-php-extensions pdo_pgsql pcntl \
    && setcap -r /usr/local/bin/frankenphp \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=fpm /app /app
COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --chmod=0755 docker/php/entrypoint /usr/local/bin/app-entrypoint
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN <<'SHELL'
set -eu
cp composer.lock /tmp/original-composer.lock
composer require laravel/octane:2.21.0 laminas/laminas-diactoros:3.9.0 symfony/psr-http-message-bridge:8.1.0 \
    --update-no-dev --minimal-changes --no-interaction --no-scripts --no-plugins --prefer-dist
php -r '$before = json_decode(file_get_contents("/tmp/original-composer.lock"), true, flags: JSON_THROW_ON_ERROR)["packages"]; $after = array_column(json_decode(file_get_contents("composer.lock"), true, flags: JSON_THROW_ON_ERROR)["packages"], null, "name"); foreach ($before as $package) { if ($after[$package["name"]] !== $package) { throw new RuntimeException("The experiment changed a baseline dependency: ".$package["name"]); } } $new = array_diff(array_keys($after), array_column($before, "name")); sort($new); if ($new !== ["laminas/laminas-diactoros", "laravel/octane", "symfony/psr-http-message-bridge"]) { throw new RuntimeException("Unexpected experimental dependency."); }'
php artisan package:discover --no-interaction
php artisan octane:install --server=frankenphp --no-interaction
apt-get purge -y unzip
rm -f /usr/local/bin/composer
rm -f bootstrap/cache/*.php /tmp/original-composer.lock
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 0750 {} +
find storage bootstrap/cache -type f -exec chmod 0640 {} +
SHELL
ARG SOURCE_REVISION
LABEL org.opencontainers.image.revision=$SOURCE_REVISION
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr XDG_CONFIG_HOME=/tmp/caddy-config XDG_DATA_HOME=/tmp/caddy-data
USER www-data:www-data
EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=2", "--max-requests=500", "--log-level=warn", "--no-interaction"]

FROM ${NGINX_IMAGE} AS octane-proxy
USER root
RUN sed -i \
    -e 's/server php:9000 resolve;/server php:8000 resolve;/' \
    -e '/include \/etc\/nginx\/fastcgi_params;/,/fastcgi_pass php_fpm;/c\            proxy_set_header Host $http_host;\n            proxy_set_header X-Forwarded-Proto $scheme;\n            proxy_pass http://php_fpm$request_uri;' \
    /etc/nginx/nginx.conf
USER nginx:nginx
RUN nginx -t
