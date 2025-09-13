#!/bin/sh

# Optimized health check for FrankenPHP/Laravel
# This script is designed to be lightweight and fast

set -e

# Check if FrankenPHP is responding
if ! timeout 2s curl -f -s -o /dev/null http://localhost:8080/health; then
    exit 1
fi

# Check if PHP-FPM/FrankenPHP process is running
if ! pgrep -x "frankenphp" > /dev/null 2>&1; then
    exit 1
fi

# Optional: Check if critical directories are accessible
if [ ! -d "/srv/storage" ] || [ ! -d "/srv/bootstrap/cache" ]; then
    exit 1
fi

# All checks passed
exit 0