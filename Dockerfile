FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer-binary
FROM node:22.22.0-bookworm-slim@sha256:dd9d21971ec4395903fa6143c2b9267d048ae01ca6d3ea96f16cb30df6187d94 AS node-binary

FROM php:8.5.10-fpm-bookworm@sha256:cc45fc347dcdef585e907fa7dab27087039f2adf7bd911c22b79a3d80cc98d08 AS php-base

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev libpq5; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl; \
    curl -fsSL https://pecl.php.net/get/redis-6.3.0.tgz -o /tmp/redis.tgz; \
    echo '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5  /tmp/redis.tgz' | sha256sum -c -; \
    pecl install /tmp/redis.tgz; \
    docker-php-ext-enable redis; \
    apt-mark manual libpq5; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS libpq-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/redis.tgz /tmp/pear /root/.pearrc; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    mkdir -p /app/storage/app/private /app/storage/app/public \
        /app/storage/framework/cache/data /app/storage/framework/sessions \
        /app/storage/framework/views /app/storage/logs /app/bootstrap/cache

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /app

FROM php-base AS composer-build
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
COPY . .
RUN php artisan package:discover --no-interaction

FROM composer-build AS assets
RUN apt-get update \
    && apt-get install -y --no-install-recommends libatomic1 libstdc++6 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node-binary /usr/local/bin/node /usr/local/bin/node
COPY --from=node-binary /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && npm ci \
    && npm run build

FROM composer-build AS production-dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
        --no-scripts --no-plugins --classmap-authoritative \
    && composer check-platform-reqs --no-dev \
    && rm -f bootstrap/cache/*.php \
    && php artisan package:discover --no-interaction

FROM php-base AS runtime
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=production-dependencies /app/artisan /app/composer.json /app/composer.lock /app/LICENSE ./
COPY --from=production-dependencies /app/app ./app
COPY --from=production-dependencies /app/bootstrap ./bootstrap
COPY --from=production-dependencies /app/config ./config
COPY --from=production-dependencies /app/database ./database
COPY --from=production-dependencies /app/resources ./resources
COPY --from=production-dependencies /app/routes ./routes
COPY --from=production-dependencies /app/stubs ./stubs
COPY --from=production-dependencies /app/vendor ./vendor
COPY --from=assets /app/public ./public

RUN find /app -type d -exec chmod 0755 {} + \
    && find /app -type f -exec chmod 0644 {} + \
    && chown -R www-data:www-data storage bootstrap/cache \
    && find storage bootstrap/cache -type d -exec chmod 0750 {} + \
    && find storage bootstrap/cache -type f -exec chmod 0640 {} +

USER www-data:www-data
EXPOSE 9000
STOPSIGNAL SIGQUIT
CMD ["php-fpm", "--nodaemonize"]

FROM nginx:1.30.5-alpine@sha256:0985e772fb9f729e6fa0980da05fca5d9c468e870eed43071545afa9d2e27d94 AS nginx
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY --from=assets /app/public /app/public
RUN rm -f /etc/nginx/conf.d/default.conf \
    && mkdir -p /tmp/nginx/client-body /tmp/nginx/fastcgi \
        /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi \
    && chown -R nginx:nginx /tmp/nginx \
    && find /tmp/nginx -type d -exec chmod 0750 {} + \
    && find /app/public -type d -exec chmod 0755 {} + \
    && find /app/public -type f -exec chmod 0644 {} +
USER nginx:nginx
RUN nginx -t
EXPOSE 8080
STOPSIGNAL SIGQUIT
ENTRYPOINT ["nginx"]
CMD ["-g", "daemon off;"]
