FROM composer/composer:2-bin AS composer
FROM ghcr.io/mlocati/php-extension-installer:2 AS php-extension-installer
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner-bin
FROM ghcr.io/oxphp/oxphp:0.12.0 AS oxphp-bin

# Follow OxPHP's Yii3 recipe: PHP CLI and extensions share the server's ZTS ABI.
FROM php:8.5.11-zts-alpine3.23 AS oxphp-base
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN apk add --no-cache libgcc \
    && install-php-extensions mbstring intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
COPY --from=oxphp-bin /usr/local/bin/oxphp /usr/local/bin/oxphp
COPY --from=oxphp-bin /usr/local/lib/liboxphp_bridge.so /usr/local/lib/
COPY --from=oxphp-bin /usr/local/lib/php/extensions/ /tmp/oxphp-ext/
RUN cp /tmp/oxphp-ext/*/oxphp_sapi.so "$(php-config --extension-dir)/" \
    && rm -rf /tmp/oxphp-ext \
    && echo 'extension=oxphp_sapi.so' > "$PHP_INI_DIR/conf.d/oxphp.ini" \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
ENV LD_LIBRARY_PATH=/usr/local/lib \
    DOCUMENT_ROOT=/app/public \
    LISTEN_ADDR=0.0.0.0:80 \
    PHP_WORKERS=20 \
    QUEUE_WAIT_TIMEOUT_MS=30000
WORKDIR /app
CMD ["oxphp"]

FROM oxphp-base AS oxphp-classic
ENV ENTRY_FILE=index.php

FROM oxphp-base AS oxphp-worker
ENV WORKER_MODE_ENABLED=true \
    ENTRY_FILE=/app/worker-oxphp.php \
    WORKER_MAX_MEMORY_MIB=256 \
    MAX_REQUESTS=10000

FROM dunglas/frankenphp:1.13.0-php8.5-bookworm AS frankenphp-base
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app

FROM frankenphp-base AS frankenphp-classic
COPY docker/runtimes/frankenphp-classic.Caddyfile /etc/frankenphp/Caddyfile

FROM frankenphp-base AS frankenphp-worker
COPY docker/runtimes/frankenphp-worker.Caddyfile /etc/frankenphp/Caddyfile

FROM php:8.5.11-cli-bookworm AS php-cli-base
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions opcache mbstring intl dom curl sockets pdo_pgsql zip
COPY --from=composer /composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app
FROM php-cli-base AS workerman
RUN install-php-extensions pcntl
# Pin upstream source directly: PECL's release endpoint is not always available.
ADD --checksum=sha256:4ac43b6ba60d762f530e941fb406e66cc17a089401c7f9a58b048a780edba44b \
    https://bitbucket.org/osmanov/pecl-event/get/3.1.6.tar.gz /tmp/event.tar.gz
RUN apt-get update \
    && apt-get install -y --no-install-recommends libevent-dev libssl-dev \
    && mkdir /tmp/event \
    && tar -xzf /tmp/event.tar.gz -C /tmp/event --strip-components=1 \
    && cd /tmp/event && phpize \
    && ./configure --with-event-core --with-event-extra --with-event-openssl --enable-event-sockets \
    && make -j"$(nproc)" && make install \
    && docker-php-ext-enable --ini-name zz-event.ini event \
    && rm -rf /tmp/event /tmp/event.tar.gz /var/lib/apt/lists/*
STOPSIGNAL SIGQUIT
CMD ["php", "worker-workerman.php", "start"]

FROM php-cli-base AS swoole
RUN install-php-extensions swoole-6.2.3
CMD ["php", "worker-swoole.php"]

FROM php-cli-base AS roadrunner
COPY --from=roadrunner-bin /usr/bin/rr /usr/local/bin/rr
CMD ["rr", "serve", "-c", "docker/runtimes/roadrunner.yaml", "-w", "/app"]

FROM php:8.5.11-fpm-bookworm AS php-fpm
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
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app

FROM php:8.5.11-apache-bookworm AS apache-mod-php
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
COPY docker/runtimes/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/runtimes/apache-prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
RUN a2enmod rewrite && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app

# Build the pinned FreeUnit release against the same PHP version as the other runtimes.
FROM php:8.5.11-cli-trixie AS freeunit-build
RUN set -eux; \
    sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends build-essential curl libssl-dev libpcre2-dev zlib1g-dev libzstd-dev libbrotli-dev; \
    curl -fsSL https://github.com/freeunitorg/freeunit/archive/refs/tags/1.37.0.tar.gz -o /tmp/freeunit.tar.gz; \
    echo '8ae237d5a32d77bb8a7426d844ce75aeb18583a4e47318f55e17839d865fad8c  /tmp/freeunit.tar.gz' | sha256sum -c -; \
    mkdir /usr/src/freeunit; \
    tar -xzf /tmp/freeunit.tar.gz -C /usr/src/freeunit --strip-components=1; \
    cd /usr/src/freeunit; \
    ./configure --prefix=/usr --statedir=/var/lib/unit --control=unix:/var/run/control.unit.sock \
        --runstatedir=/var/run --pid=/var/run/unit.pid --logdir=/var/log --log=/var/log/unit.log \
        --tmpdir=/var/tmp --user=www-data --group=www-data --modulesdir=/usr/lib/unit/modules \
        --openssl --zlib --zstd --brotli; \
    ./configure php; \
    make -j"$(nproc)"; \
    make install

FROM php:8.5.11-cli-trixie AS freeunit
COPY --from=freeunit-build /usr/sbin/unitd /usr/sbin/unitd
COPY --from=freeunit-build /usr/lib/unit/modules /usr/lib/unit/modules
# FreeUnit 1.37.0 no longer includes this script; retain the pinned 1.36.1 entrypoint.
ADD --chmod=755 --checksum=sha256:3ae4c711f022a351c0b89042c4fde21260fa0578d28fd52e1585cad15f5df91b \
    https://raw.githubusercontent.com/freeunitorg/freeunit/1.36.1/pkg/docker/docker-entrypoint.sh /usr/local/bin/freeunit-entrypoint.sh
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN set -eux; \
    for source in /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; do \
        if [ -f "$source" ]; then \
            sed -i 's|http://deb.debian.org|https://deb.debian.org|g; s|http://security.debian.org|https://security.debian.org|g' "$source"; \
        fi; \
    done; \
    printf 'Acquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries; \
    install-php-extensions intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
COPY docker/runtimes/freeunit.json /docker-entrypoint.d/config.json
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libssl3t64 libpcre2-8-0 zlib1g libzstd1 libbrotli1 \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/lib/unit \
    && ln -sf /dev/stderr /var/log/unit.log
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/freeunit-entrypoint.sh"]
CMD ["unitd", "--no-daemon", "--control", "unix:/var/run/control.unit.sock"]

FROM php:8.5.11-cli-trixie AS rapira
COPY --from=ghcr.io/rapira-rs/rapira:nightly-php8.5 / /
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN install-php-extensions mbstring intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app
CMD ["rapira", "serve", "docker/runtimes/rapira.toml"]
