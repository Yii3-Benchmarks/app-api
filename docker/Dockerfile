FROM composer/composer:2-bin AS composer
FROM ghcr.io/mlocati/php-extension-installer:2 AS php-extension-installer
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.13 AS roadrunner

FROM php:8.5-cli-bookworm AS base

COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

RUN apt update && apt -y install \
    unzip

RUN install-php-extensions \
    opcache \
    mbstring \
    intl \
    dom \
    ctype \
    curl \
    phar \
    openssl \
    sockets \
    xml \
    xmlwriter \
    simplexml \
    pdo \
    zip

ENV LC_ALL=C.UTF-8
WORKDIR /app
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 CMD php -r "exit(@file_get_contents('http://127.0.0.1:2114/health?plugin=http') === false ? 1 : 0);"

#
# Development
#

FROM base AS dev
ARG USER_ID=10001
ARG GROUP_ID=10001
ARG USER_NAME=appuser
ARG GROUP_NAME=appuser

RUN install-php-extensions \
    xdebug

COPY --from=composer /composer /usr/bin/composer

RUN \
	groupadd --gid ${GROUP_ID} ${GROUP_NAME}; \
    useradd --gid ${GROUP_ID} --uid ${USER_ID} ${GROUP_NAME}
USER ${USER_NAME}

#
# Production
#

FROM base AS prod-builder
COPY --from=composer /composer /usr/bin/composer
COPY .. /app
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-progress --no-interaction --no-scripts --classmap-authoritative && \
    rm composer.lock composer.json

FROM base AS prod
ENV APP_ENV=prod
COPY --from=prod-builder --chown=www-data:www-data /app /app
USER www-data
CMD ["rr", "serve", "-c", ".rr.yaml", "-w", "/app"]
