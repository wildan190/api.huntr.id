# ==========================================
# Base Stage
# ==========================================
FROM php:8.3-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    curl \
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
    openssl

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    gd \
    zip \
    pcntl \
    opcache

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
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

# Switch to development PHP configuration
RUN mv "$PHP_INI_DIR/php.ini" "$PHP_INI_DIR/php.ini-production" \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Create non-root user for development
RUN usermod -u 1000 www-data \
    && groupmod -g 1000 www-data

USER www-data


# ==========================================
# Production Stage
# ==========================================
FROM base AS production

# Production environment
ENV APP_ENV=production
ENV APP_DEBUG=false

# Copy application files
COPY --chown=www-data:www-data . .

# Install Composer dependencies
# Use source instead of GitHub dist ZIP/codeload
RUN composer install \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --prefer-source

# Create non-root user
RUN usermod -u 1000 www-data \
    && groupmod -g 1000 www-data

USER www-data

EXPOSE 9000

CMD ["php-fpm"]