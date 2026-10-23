# ==========================================
# Base Stage
# ==========================================
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    curl \
    curl-dev \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    postgresql-dev \
    oniguruma-dev \
    fcgi \
    shadow \
    bash \
    openssl \
    openssl-dev \
    libstdc++

RUN { echo 'post_max_size = 32M'; echo 'upload_max_filesize = 32M'; } > "$PHP_INI_DIR/conf.d/crm-session-backup.ini"

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    gd \
    zip \
    pcntl

# Install Redis and Swoole extensions
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install -D 'enable-openssl="yes" enable-http2="yes" enable-swoole-curl="yes"' swoole \
    && docker-php-ext-enable swoole \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Configure PHP settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# ==========================================
# Development Stage
# ==========================================
FROM base AS development

# Install nodejs, npm, and chokidar for Octane file watching (--watch)
RUN apk add --no-cache nodejs npm \
    && npm install -g chokidar
ENV NODE_PATH="/usr/local/lib/node_modules:/usr/lib/node_modules"

# Switch back to development ini for better errors
RUN mv "$PHP_INI_DIR/php.ini" "$PHP_INI_DIR/php.ini-production" \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Create a non-root user for development to prevent permission issues
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data
USER www-data

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--watch"]

# ==========================================
# Production Stage
# ==========================================
FROM base AS production

# PHP 8.5 ships OPcache with the runtime; this file only tunes it for immutable production images.
COPY docker/php/conf.d/zz-production-performance.ini $PHP_INI_DIR/conf.d/zz-production-performance.ini

# Set environment variables
ENV APP_ENV=production
ENV APP_DEBUG=false

# Copy application files
COPY --chown=www-data:www-data . .

# Run Composer Install optimized for production
RUN git config --global --add safe.directory /var/www
RUN composer install --no-interaction --no-dev --optimize-autoloader --prefer-dist --no-scripts

# Create a non-root user for security
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data
USER www-data

EXPOSE 8000
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--workers=auto", "--max-requests=10000"]
