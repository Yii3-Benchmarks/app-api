FROM composer/composer:2-bin AS composer
FROM ghcr.io/mlocati/php-extension-installer:2 AS php-extension-installer
FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner-bin

FROM dunglas/frankenphp:1.12.7-php8.5-bookworm AS frankenphp-base
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

FROM php:8.5.10-cli-bookworm AS roadrunner
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY --from=roadrunner-bin /usr/bin/rr /usr/local/bin/rr
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
CMD ["rr", "serve", "-c", "docker/runtimes/roadrunner.yaml", "-w", "/app"]

FROM php:8.5.10-fpm-bookworm AS php-fpm
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

# The published latest-php8.5 image still contains FreeUnit 1.35.5.
# Build the current release against the same PHP version as the other runtimes.
FROM php:8.5.10-cli-trixie AS freeunit-build
RUN set -eux; \
    sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources; \
    apt-get update; \
    apt-get install -y --no-install-recommends build-essential curl libssl-dev libpcre2-dev zlib1g-dev libzstd-dev libbrotli-dev; \
    curl -fsSL https://github.com/freeunitorg/freeunit/archive/refs/tags/1.36.1.tar.gz -o /tmp/freeunit.tar.gz; \
    echo '091a2e1587f72016fd74d95019fc3b92e62c66a23548f79dd8853c175dee34c5  /tmp/freeunit.tar.gz' | sha256sum -c -; \
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

FROM php:8.5.10-cli-trixie AS freeunit
COPY --from=freeunit-build /usr/sbin/unitd /usr/sbin/unitd
COPY --from=freeunit-build /usr/lib/unit/modules /usr/lib/unit/modules
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

FROM php:8.5.10-cli-trixie AS rapira
COPY --from=ghcr.io/rapira-rs/rapira:0.8.1-php8.5 / /
COPY --from=php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
RUN install-php-extensions mbstring intl pdo_pgsql
COPY --from=composer /composer /usr/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/runtimes/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /app
CMD ["rapira", "serve", "--config", "docker/runtimes/rapira.toml"]
