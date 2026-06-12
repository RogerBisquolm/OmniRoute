#!/bin/bash
set -e

# Clear host-generated bootstrap cache files to avoid container mismatch
echo "Clearing bootstrap cache..."
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes.php

echo "Waiting for MariaDB Master database to be online..."

# Health check loop using native PHP PDO to verify connection credentials and availability
php -r "
\$host = getenv('DB_HOST') ?: '127.0.0.1';
\$port = getenv('DB_PORT') ?: '3306';
\$db = getenv('DB_DATABASE') ?: 'gateway_db';
\$user = getenv('DB_USERNAME') ?: 'root';
\$pwd = getenv('DB_PASSWORD') ?: '';

for (\$i = 0; \$i < 30; \$i++) {
    try {
        new PDO(\"mysql:host=\$host;port=\$port;dbname=\$db\", \$user, \$pwd);
        echo 'MariaDB Master is online and accepting connections.\n';
        exit(0);
    } catch (PDOException \$e) {
        echo 'Waiting for MariaDB Master...\n';
        sleep(2);
    }
}
echo 'Failed to connect to MariaDB Master database.\n';
exit(1);
"

echo "Running Database Migrations & Seeders..."
php artisan migrate --force --seed

echo "Fetching initial model prices from OpenRouter..."
php artisan pricing:update || true


echo "Starting FrankenPHP server..."
exec docker-php-entrypoint frankenphp run --config /etc/caddy/Caddyfile
