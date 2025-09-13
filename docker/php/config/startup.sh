#!/bin/sh

# Laravel Optimization Startup Script
# This script runs optimization commands before starting FrankenPHP

set -e

echo "Starting Laravel optimization..."

# Function to check if Laravel is installed
check_laravel() {
    if [ ! -f "/srv/artisan" ]; then
        echo "Laravel not found. Skipping optimizations."
        return 1
    fi
    return 0
}

# Function to run optimization in production
run_production_optimizations() {
    echo "Running production optimizations..."
    
    # Clear old caches
    php artisan cache:clear 2>/dev/null || true
    
    # Generate optimized autoloader
    if [ -f "/srv/composer.json" ]; then
        composer dump-autoload --optimize --no-dev --no-interaction 2>/dev/null || true
    fi
    
    # Cache configuration
    php artisan config:cache || {
        echo "Failed to cache config. Running config:clear..."
        php artisan config:clear
    }
    
    # Cache routes
    php artisan route:cache || {
        echo "Failed to cache routes. Running route:clear..."
        php artisan route:clear
    }
    
    # Cache views
    php artisan view:cache || {
        echo "Failed to cache views. Running view:clear..."
        php artisan view:clear
    }
    
    # Cache events (if available)
    php artisan event:cache 2>/dev/null || true
    
    # Optimize for production (if command exists)
    php artisan optimize 2>/dev/null || true
    
    echo "Production optimizations completed!"
}

# Function to run development setup
run_development_setup() {
    echo "Running development setup..."
    
    # Clear all caches for fresh development
    php artisan cache:clear 2>/dev/null || true
    php artisan config:clear 2>/dev/null || true
    php artisan route:clear 2>/dev/null || true
    php artisan view:clear 2>/dev/null || true
    
    echo "Development setup completed!"
}

# Check if we're in production or development
if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "prod" ]; then
    ENVIRONMENT="production"
else
    ENVIRONMENT="development"
fi

echo "Environment detected: $ENVIRONMENT"

# Run optimizations if Laravel is installed
if check_laravel; then
    if [ "$ENVIRONMENT" = "production" ]; then
        run_production_optimizations
    else
        run_development_setup
    fi
fi

# Create necessary directories
mkdir -p /srv/storage/app/public \
         /srv/storage/framework/cache \
         /srv/storage/framework/sessions \
         /srv/storage/framework/testing \
         /srv/storage/framework/views \
         /srv/storage/logs \
         /srv/bootstrap/cache 2>/dev/null || true

# Ensure correct permissions
chmod -R 755 /srv/storage /srv/bootstrap/cache 2>/dev/null || true

# Run database migrations if AUTO_MIGRATE is set
if [ "$AUTO_MIGRATE" = "true" ] && check_laravel; then
    echo "Running database migrations..."
    php artisan migrate --force || echo "Migration failed or database not configured"
fi

echo "Startup optimizations complete. Starting FrankenPHP..."

# Execute the main command
exec "$@"