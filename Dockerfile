# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Airking Inventory — production image for the Laravel API (Render / any OCI host)
#
# The Laravel app lives in ./back-end, so build with the REPOSITORY ROOT as the
# build context (on Render: leave "Root Directory" blank, set Dockerfile Path
# to ./Dockerfile). The React frontend in ./frontend is NOT part of this image;
# deploy it separately as a static site.
# ---------------------------------------------------------------------------

# --------------------------- Stage 1: dependencies -------------------------
FROM composer:2.8 AS vendor

WORKDIR /app

# Copy the manifests first so Docker can cache the dependency layer: it is only
# invalidated when composer.json / composer.lock actually change.
COPY back-end/composer.json back-end/composer.lock ./

# --no-scripts: the post-autoload-dump hook boots Laravel (package:discover),
# which needs both the app files and the PHP extensions — neither is present in
# this stage. It is run in the runtime stage instead.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

COPY back-end/ ./

RUN composer dump-autoload --no-dev --optimize --no-interaction


# ----------------------------- Stage 2: runtime ----------------------------
FROM php:8.2-apache-bookworm AS runtime

# The -dev packages are the headers each extension compiles against. They are
# kept (rather than purged) on purpose: purging with --auto-remove on bookworm
# also takes the runtime .so files some of these extensions link against, and a
# ~90 MB smaller image is not worth a container that dies on its first request.
# postgresql-client ships psql/pg_dump for one-off maintenance on the Aiven DB.
#
# Debian package versions are deliberately unpinned: a pinned version vanishes
# from the archive when Debian issues a point release, turning a reproducible
# build into one that cannot build at all. The base image tag pins this image.
# hadolint ignore=DL3008
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev \
        libonig-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxml2-dev \
        postgresql-client \
        unzip \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        bcmath \
        intl \
        zip \
        gd \
        exif \
        pcntl \
    ; \
    docker-php-ext-enable opcache; \
    rm -rf /var/lib/apt/lists/*

# Fail the build rather than the first request if an extension is missing.
# Asserted in PHP rather than `php -m | grep`: under /bin/sh a pipeline reports
# only the LAST command's exit status, so a crashing php would be masked by a
# grep that simply found nothing to match.
# hadolint ignore=SC2016
RUN php -r 'foreach (["pdo_pgsql", "pgsql", "mbstring", "bcmath", "gd", "zip", "intl"] as $ext) { \
        if (! extension_loaded($ext)) { fwrite(STDERR, "FATAL: missing PHP extension: $ext\n"); exit(1); } \
    } \
    echo "PHP ", PHP_VERSION, " with all required extensions\n";'

# Apache: rewrite is required by Laravel's public/.htaccess; headers and
# remoteip let the app see the real client IP behind Render's proxy.
RUN a2enmod rewrite headers remoteip \
    && a2dissite 000-default

COPY docker/apache-laravel.conf /etc/apache2/sites-available/laravel.conf
COPY docker/apache-ports.conf   /etc/apache2/ports.conf
RUN a2ensite laravel

COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

WORKDIR /var/www/html

# App source, then vendor/ (built in stage 1) on top.
COPY --chown=www-data:www-data back-end/ /var/www/html/
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/vendor

# public/storage is a symlink to an absolute *host* path in the repo; drop it so
# the entrypoint can recreate it correctly inside the container.
# NB: paths are written out in full — RUN uses /bin/sh (dash), which does not
# expand {a,b,c} braces and would create one literally-named directory.
RUN rm -rf /var/www/html/public/storage \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Runs Laravel's discovery hook, which stage 1 deliberately skipped.
RUN php artisan package:discover --ansi

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Render injects PORT; this default keeps `docker run` working locally.
ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

EXPOSE 10000

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
