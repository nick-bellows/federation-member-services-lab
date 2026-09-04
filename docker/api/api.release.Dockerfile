# Release image for the API (ADR-0015, docs/DEPLOYMENT.md, docs/RELEASE.md).
#
# Differs from docker/api/api.Dockerfile, the development image:
#   - dependencies are installed at build time without development packages,
#     the admin assets are built, and no bind mount replaces the source;
#   - no .env is copied (see api.release.Dockerfile.dockerignore): every
#     setting comes from the environment;
#   - the build toolchain lives in a separate stage and is not shipped;
#   - the entrypoint caches configuration and waits for the configured
#     database, and migrates only when RUN_MIGRATIONS=1 (a one-off task);
#   - a HEALTHCHECK asks liveness.
#
# Build from the repository root:
#   docker build -f docker/api/api.release.Dockerfile -t federation-api:<git sha> .

# ---- Stage 1: PHP dependencies -------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY api/composer.json api/composer.lock ./
# Scripts need the framework; run them in the app stage instead.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader --ignore-platform-reqs

# ---- Stage 2: admin (Filament/Vite) assets -------------------------------
FROM node:20-alpine AS assets
WORKDIR /app
COPY api/package.json api/package-lock.json ./
RUN npm ci --no-fund --no-audit
COPY api/ ./
# The admin theme imports Filament's stylesheet from vendor/ (resources/css/filament/admin/theme.css).
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- Stage 3: runtime ----------------------------------------------------
FROM php:8.3-fpm-alpine3.23 AS runtime

ENV USER_NAME=verein GROUP_NAME=verein USER_ID=1000 GROUP_ID=1000
ENV DOCUMENT_ROOT=/var/www/html/public
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS="0"

RUN addgroup --gid ${GROUP_ID} ${GROUP_NAME} \
    && adduser --disabled-password --gecos '' --uid ${USER_ID} --ingroup ${GROUP_NAME} ${USER_NAME}

# Runtime libraries only; the compilers are removed after the extensions build.
RUN apk add --no-cache bash curl nginx icu-libs libzip libpng libjpeg-turbo freetype imagemagick ghostscript libpq \
    && apk add --no-cache --virtual .build-deps icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        imagemagick-dev postgresql-dev autoconf g++ make pkgconfig \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql intl zip opcache gd bcmath exif \
    && pecl install imagick && docker-php-ext-enable imagick \
    && apk del .build-deps

RUN sed -ri -e "s!user nginx!user ${USER_NAME}!g" /etc/nginx/nginx.conf \
    && sed -ri -e "s!user = www-data!user = ${USER_NAME}!g" /usr/local/etc/php-fpm.d/www.conf \
    && sed -ri -e "s!group = www-data!group = ${GROUP_NAME}!g" /usr/local/etc/php-fpm.d/www.conf \
    && printf '\ncatch_workers_output = yes\ndecorate_workers_output = no\n' >> /usr/local/etc/php-fpm.d/www.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/api/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/api/php.ini "$PHP_INI_DIR/conf.d/zzz-custom-php.ini"
COPY docker/api/php-fpm-www.conf /usr/local/etc/php-fpm.d/zzz-www.conf

WORKDIR /var/www/html
COPY --chown=${USER_NAME}:${GROUP_NAME} api/ ./
COPY --chown=${USER_NAME}:${GROUP_NAME} --from=vendor /app/vendor ./vendor
COPY --chown=${USER_NAME}:${GROUP_NAME} --from=assets /app/public/build ./public/build
COPY --chown=${USER_NAME}:${GROUP_NAME} docker/api/release-entrypoint.sh /var/www/docker/release-entrypoint.sh
COPY --chown=${USER_NAME}:${GROUP_NAME} docker/api/startup.sh /var/www/docker/startup.sh

# Autoloader, package discovery and the admin panel's published assets, now
# that the framework is present. Composer is borrowed from its image and not
# shipped.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && rm -f /usr/local/bin/composer \
    && mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache \
    && chown -R ${USER_NAME}:${GROUP_NAME} /var/www/html \
    && chmod +x /var/www/docker/release-entrypoint.sh /var/www/docker/startup.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/api/health/live || exit 1

ENTRYPOINT ["bash", "/var/www/docker/release-entrypoint.sh"]
CMD ["bash", "/var/www/docker/startup.sh"]
