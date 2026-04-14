FROM composer/composer:2-bin AS composer

FROM php:8.5-fpm-bookworm AS base

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions opcache mbstring intl dom

RUN apt-get update && apt-get install -y \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ENV LC_ALL=C.UTF-8

WORKDIR /app

#
# Development
#

FROM base AS dev
ARG USER_ID=10001
ARG GROUP_ID=10001
ARG USER_NAME=appuser
ARG GROUP_NAME=appuser

COPY --from=composer /composer /usr/bin/composer

RUN \
    groupadd --gid ${GROUP_ID} ${GROUP_NAME} && \
    useradd --gid ${GROUP_ID} --uid ${USER_ID} ${USER_NAME}

COPY docker/dev/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

USER ${USER_NAME}

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
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY --from=prod-builder --chown=www-data:www-data /app /app
USER www-data
