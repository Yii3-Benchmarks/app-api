FROM composer/composer:2-bin AS composer
FROM ghcr.io/mlocati/php-extension-installer:2 AS php-extension-installer
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.13 AS roadrunner-bin

FROM dunglas/frankenphp:1-php8.5-bookworm AS frankenphp-base
RUN install-php-extensions opcache mbstring intl dom ctype curl phar openssl xml xmlwriter simplexml pdo pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
WORKDIR /app

FROM frankenphp-base AS frankenphp-classic
COPY docker/runtimes/frankenphp-classic.Caddyfile /etc/frankenphp/Caddyfile

FROM frankenphp-base AS frankenphp-worker
COPY docker/runtimes/frankenphp-worker.Caddyfile /etc/frankenphp/Caddyfile

FROM php:8.5-cli-bookworm AS roadrunner
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=roadrunner-bin /usr/bin/rr /usr/local/bin/rr
RUN install-php-extensions opcache mbstring intl dom ctype curl phar openssl sockets xml xmlwriter simplexml pdo pdo_pgsql zip grpc
COPY --from=composer /composer /usr/bin/composer
WORKDIR /app
CMD ["rr", "serve", "-c", "docker/runtimes/roadrunner.yaml", "-w", "/app"]

FROM php:8.5-fpm-bookworm AS php-fpm
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN install-php-extensions opcache mbstring intl dom pdo pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
COPY docker/runtimes/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
WORKDIR /app

FROM ghcr.io/freeunitorg/freeunit:latest-php8.5 AS freeunit
RUN install-php-extensions opcache mbstring intl dom ctype curl phar openssl xml xmlwriter simplexml pdo pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
COPY docker/runtimes/freeunit.json /var/lib/unit/config.json
WORKDIR /app
