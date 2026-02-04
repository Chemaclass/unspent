# syntax=docker/dockerfile:1

# Development image for Unspent PHP library
# Includes: PHP 8.4, Composer, Xdebug, PCOV

FROM php:8.4-cli-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install zip

# Install PCOV for fast code coverage
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Xdebug for debugging (disabled by default, enable via XDEBUG_MODE)
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Configure PHP for development
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Configure Xdebug (off by default, controlled by XDEBUG_MODE env var)
COPY docker/php/xdebug.ini $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini

# Configure PCOV
COPY docker/php/pcov.ini $PHP_INI_DIR/conf.d/docker-php-ext-pcov.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create non-root user for security
RUN addgroup -g 1000 dev && adduser -u 1000 -G dev -s /bin/sh -D dev

# Set working directory
WORKDIR /app

# Switch to non-root user
USER dev

# Default command
CMD ["php", "-v"]

# ============================================
# Development stage with all tools
# ============================================
FROM base AS dev

# Keep as non-root user
USER dev

# Composer cache for faster installs
ENV COMPOSER_HOME=/home/dev/.composer
RUN mkdir -p $COMPOSER_HOME/cache

# Default to shell for interactive development
CMD ["/bin/sh"]
