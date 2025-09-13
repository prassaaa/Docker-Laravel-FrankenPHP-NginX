# Use a build argument to select the final stage (development or production)
ARG ENVIRONMENT=development

# STAGE 1: Builder
# This stage installs all build-time dependencies and tools.
FROM dunglas/frankenphp:1.7-php8.4-alpine AS builder

# Cache package index separately for better caching
RUN apk update && apk upgrade

# Install build-time OS dependencies and PECL for extensions
# Split into logical groups for better cache invalidation
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    linux-headers

# Install development libraries
RUN apk add --no-cache \
    curl-dev \
    icu-dev \
    libxml2-dev \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    postgresql-dev

# Install build tools
RUN apk add --no-cache \
    git \
    make \
    autoconf \
    g++

# Configure PHP extensions before installation for better caching
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-configure intl && \
    docker-php-ext-configure zip

# Install PHP extensions in one layer
RUN docker-php-ext-install -j$(nproc) \
    bcmath \
    gd \
    intl \
    mbstring \
    pcntl \
    pdo_pgsql \
    zip

# Install PECL extensions separately for cache efficiency
RUN pecl channel-update pecl.php.net && \
    pecl install --onlyreqdeps --nobuild redis-6.2.0 && \
    cd "$(pecl config-get temp_dir)/redis" && \
    phpize && ./configure && make && make install && \
    docker-php-ext-enable redis && \
    cd - && rm -rf "$(pecl config-get temp_dir)/redis"

# Install Excimer extension
RUN pecl install --onlyreqdeps --nobuild excimer && \
    cd "$(pecl config-get temp_dir)/excimer" && \
    phpize && ./configure && make && make install && \
    docker-php-ext-enable excimer && \
    cd - && rm -rf "$(pecl config-get temp_dir)/excimer"

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install Supercronic
RUN curl -fsSLo /usr/local/bin/supercronic https://github.com/aptible/supercronic/releases/download/v0.2.29/supercronic-linux-amd64 \
    && chmod +x /usr/local/bin/supercronic


# STAGE 2: Base
# This is the clean base for your final images, containing no build tools.
FROM dunglas/frankenphp:1.7-php8.4-alpine AS base

# Accept user/group IDs as arguments to avoid permission errors
ARG USER_ID=1000
ARG GROUP_ID=1000

# Copy only the necessary tools from the builder stage
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer
COPY --from=builder /usr/local/bin/supercronic /usr/local/bin/supercronic

# Copy compiled PHP extensions from the builder stage
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/

# Copy extension configurations
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Install only RUNTIME OS dependencies
# Add --virtual flag for better cleanup
RUN apk add --no-cache \
    icu-libs \
    libxml2 \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype \
    oniguruma \
    libpq \
    && rm -rf /var/cache/apk/*

# Create a non-root user with the provided IDs. This is the main fix.
RUN addgroup -g ${GROUP_ID} -S appgroup && \
    adduser -u ${USER_ID} -S appuser -G appgroup

# Create and set permissions for Caddy data directory
RUN mkdir -p /data/caddy && chown -R appuser:appgroup /data

# Copy PHP configuration files
COPY config/*.ini $PHP_INI_DIR/conf.d/
COPY config/Caddyfile /etc/caddy/Caddyfile
COPY config/preload.php /srv/docker/php/config/preload.php


# STAGE 3: Development
# The development-specific image
FROM base AS development
# Re-declare ARGs for this stage
ARG USER_ID
ARG GROUP_ID

# Use development php.ini
RUN cp $PHP_INI_DIR/php.ini-development $PHP_INI_DIR/php.ini

# Copy development-specific configurations
RUN mv $PHP_INI_DIR/conf.d/opcache.ini $PHP_INI_DIR/conf.d/opcache.ini.prod && \
    cp $PHP_INI_DIR/conf.d/opcache-dev.ini $PHP_INI_DIR/conf.d/opcache.ini && \
    rm $PHP_INI_DIR/conf.d/opcache-dev.ini

# Create coverage directory and set correct ownership using the dynamic user
RUN mkdir -p /opt/phpstorm-coverage && \
    chown -R ${USER_ID}:${GROUP_ID} /opt/phpstorm-coverage


# STAGE 4: Production
# The production-specific image
FROM base AS production
# Use production php.ini
RUN cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini

# Remove development configurations for production
RUN rm -f $PHP_INI_DIR/conf.d/opcache-dev.ini


# FINAL STAGE
# Select the final image based on the ENVIRONMENT build argument
FROM ${ENVIRONMENT}

# Copy startup script and health check
COPY --chmod=755 config/startup.sh /usr/local/bin/startup.sh
COPY --chmod=755 config/healthcheck.sh /usr/local/bin/healthcheck.sh
COPY config/health-endpoint.php /srv/public/health.php

# Environment variables for FrankenPHP
ENV FRANKENPHP_CONFIG=/etc/caddy/Caddyfile

# Set the working directory
WORKDIR /srv

# Switch to the non-root user
USER appuser

# Use startup script as entrypoint
ENTRYPOINT ["/usr/local/bin/startup.sh"]

# Health check configuration
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD /usr/local/bin/healthcheck.sh || exit 1

# Default command
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
