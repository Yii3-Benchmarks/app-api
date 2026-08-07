FROM composer/composer:2-bin AS composer
FROM ghcr.io/mlocati/php-extension-installer:2 AS php-extension-installer
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.13 AS roadrunner-bin

FROM dunglas/frankenphp:1-php8.5-bookworm AS frankenphp-base
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
WORKDIR /app

FROM frankenphp-base AS frankenphp-classic
COPY docker/runtimes/frankenphp-classic.Caddyfile /etc/frankenphp/Caddyfile

FROM frankenphp-base AS frankenphp-worker
COPY docker/runtimes/frankenphp-worker.Caddyfile /etc/frankenphp/Caddyfile

FROM php:8.5-cli-bookworm AS roadrunner
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=roadrunner-bin /usr/bin/rr /usr/local/bin/rr
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions opcache mbstring intl dom curl sockets pdo_pgsql zip grpc
COPY --from=composer /composer /usr/bin/composer
WORKDIR /app
CMD ["rr", "serve", "-c", "docker/runtimes/roadrunner.yaml", "-w", "/app"]

FROM php:8.5-fpm-bookworm AS php-fpm
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions opcache mbstring intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
COPY docker/runtimes/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
WORKDIR /app

FROM ghcr.io/freeunitorg/freeunit:latest-php8.5 AS freeunit
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
COPY docker/runtimes/freeunit.json /var/lib/unit/config.json
WORKDIR /app
