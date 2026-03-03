# =============================================================================
# Async PHP Platform — Multi-stage Dockerfile
# =============================================================================
# Stages:
#   base — PHP 8.4 CLI (Debian bookworm) + OpenSwoole extension
#   dev  — base + Composer (for local development)
#   prod — base + OPcache + non-root user + HEALTHCHECK
#
# Architecture: supports amd64 and arm64 (no arch-specific binaries)
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1: base — PHP 8.4 CLI + OpenSwoole
# ---------------------------------------------------------------------------
# libcurl4-openssl-dev: required for OpenSwoole curl hook support (SWOOLE_HOOK_CURL)
# libssl-dev: required for SSL/TLS hook support
# The effective status of SWOOLE_HOOK_CURL depends on OpenSwoole compilation —
# verified at boot via startup check that logs "SWOOLE_HOOK_CURL active: yes/no"
FROM php:8.4-cli-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    libzip-dev \
    build-essential \
    autoconf \
    pkg-config \
    libssl-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install zip sockets \
    && pecl install openswoole-25.2.0 \
    && docker-php-ext-enable openswoole \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# Verify critical extensions are available
RUN php -r " \
    echo 'PHP version: ' . PHP_VERSION . PHP_EOL; \
    echo 'OpenSwoole version: ' . OPENSWOOLE_VERSION . PHP_EOL; \
    echo 'curl extension: ' . (extension_loaded('curl') ? 'yes' : 'no') . PHP_EOL; \
    "

WORKDIR /app

# ---------------------------------------------------------------------------
# Stage 2: dev — base + Composer
# ---------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
EXPOSE 8080

CMD ["php", "bin/console", "async:serve"]

# ---------------------------------------------------------------------------
# Stage 3: prod — base + OPcache, non-root user, HEALTHCHECK
# ---------------------------------------------------------------------------
FROM base AS prod

# Enable OPcache with production-tuned configuration
RUN docker-php-ext-enable opcache
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install Composer for dependency installation during build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create non-root user
RUN addgroup --system app && adduser --system --ingroup app app

# Install production dependencies only
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction \
    && rm -f /usr/bin/composer

# Copy application code
COPY . .

# Ensure var/ directory exists and is writable by app user
RUN mkdir -p /app/var && chown -R app:app /app

USER app
EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
    CMD php -r "echo file_get_contents('http://127.0.0.1:8080/healthz');" || exit 1

CMD ["php", "bin/console", "async:run"]

FROM dev AS dev-xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug
