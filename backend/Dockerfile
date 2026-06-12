FROM dunglas/frankenphp:1.1-php8.3-alpine

# Set working directory
WORKDIR /app

# Install system dependencies and helper scripts
RUN apk add --no-cache \
    git \
    unzip \
    bash \
    mariadb-client

# Install PHP extensions required by Laravel/Octane
RUN install-php-extensions \
    pcntl \
    pdo_mysql \
    redis \
    opcache \
    zip \
    intl

# Set FrankenPHP Caddy server root
ENV FRANKENPHP_DOCUMENT_ROOT=/app/public

# Copy application files
COPY . .

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install --no-interaction --no-dev --optimize-autoloader

# Configure entrypoint script permissions
RUN chmod +x /app/docker-entrypoint.sh

# Expose port (FrankenPHP listens on port 80 by default in the alpine image)
EXPOSE 80

# Set entrypoint
ENTRYPOINT ["/app/docker-entrypoint.sh"]
