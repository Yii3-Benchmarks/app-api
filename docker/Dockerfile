FROM composer/composer:2-bin AS composer

FROM ghcr.io/freeunitorg/freeunit:latest-php8.5 AS base

RUN apt update && apt -y install \
    unzip \
    curl

RUN install-php-extensions \
    opcache \
    mbstring \
    intl \
    dom \
    ctype \
    curl \
    phar \
    openssl \
    xml \
    xmlwriter \
    simplexml \
    pdo \
    pdo_pgsql

ENV LC_ALL=C.UTF-8

#
# Development
#

FROM base AS dev
ARG USER_ID=10001
ARG GROUP_ID=10001
ARG USER_NAME=appuser
ARG GROUP_NAME=appuser

# RUN install-php-extensions \
#    xdebug

COPY --from=composer /composer /usr/bin/composer

RUN \
        groupadd --gid ${GROUP_ID} ${GROUP_NAME}; \
    useradd --gid ${GROUP_ID} --uid ${USER_ID} ${GROUP_NAME}; \
        # Give write access to /var/run for Unit control socket
        mkdir -p /var/run && chown -R ${USER_NAME}:${GROUP_NAME} /var/run; \
        # Give write access to runtime directory
        mkdir -p /app/runtime && chown -R ${USER_NAME}:${GROUP_NAME} /app/runtime
USER ${USER_NAME}

COPY docker/dev/unit-config.json /var/lib/unit/config.json

#
# Production
#

FROM base AS prod-builder
COPY --from=composer /composer /usr/bin/composer
COPY .. /app
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-progress --no-interaction --classmap-authoritative && \
    rm composer.lock composer.json

FROM base AS prod
ENV APP_ENV=prod
COPY docker/unit-config.json /var/lib/unit/config.json
COPY --from=prod-builder --chown=www-data:www-data /app /app
USER www-data
