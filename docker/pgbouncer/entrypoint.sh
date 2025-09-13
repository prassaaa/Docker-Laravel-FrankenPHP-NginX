#!/bin/sh
set -e

# Generate userlist.txt from environment variables
if [ -n "$DB_USER" ] && [ -n "$DB_PASSWORD" ]; then
    echo "Generating PgBouncer userlist..."
    echo "\"$DB_USER\" \"md5$(echo -n "$DB_PASSWORD$DB_USER" | md5sum | cut -d' ' -f1)\"" > /etc/pgbouncer/userlist.txt
    
    # Add additional users if needed
    if [ -n "$STATS_USER" ] && [ -n "$STATS_PASSWORD" ]; then
        echo "\"$STATS_USER\" \"md5$(echo -n "$STATS_PASSWORD$STATS_USER" | md5sum | cut -d' ' -f1)\"" >> /etc/pgbouncer/userlist.txt
    fi
    
    if [ -n "$ADMIN_USER" ] && [ -n "$ADMIN_PASSWORD" ]; then
        echo "\"$ADMIN_USER\" \"md5$(echo -n "$ADMIN_PASSWORD$ADMIN_USER" | md5sum | cut -d' ' -f1)\"" >> /etc/pgbouncer/userlist.txt
    fi
else
    echo "Warning: DB_USER and DB_PASSWORD not set. Using default userlist.txt"
fi

# Update pgbouncer.ini with environment variables if provided
if [ -n "$POOL_MODE" ]; then
    sed -i "s/pool_mode = .*/pool_mode = $POOL_MODE/" /etc/pgbouncer/pgbouncer.ini
fi

if [ -n "$MAX_CLIENT_CONN" ]; then
    sed -i "s/max_client_conn = .*/max_client_conn = $MAX_CLIENT_CONN/" /etc/pgbouncer/pgbouncer.ini
fi

if [ -n "$DEFAULT_POOL_SIZE" ]; then
    sed -i "s/default_pool_size = .*/default_pool_size = $DEFAULT_POOL_SIZE/" /etc/pgbouncer/pgbouncer.ini
fi

if [ -n "$MIN_POOL_SIZE" ]; then
    sed -i "s/min_pool_size = .*/min_pool_size = $MIN_POOL_SIZE/" /etc/pgbouncer/pgbouncer.ini
fi

# Update database configuration
if [ -n "$DB_HOST" ] && [ -n "$DB_PORT" ] && [ -n "$DB_NAME" ]; then
    sed -i "s/laravel_db = .*/laravel_db = host=$DB_HOST port=$DB_PORT dbname=$DB_NAME/" /etc/pgbouncer/pgbouncer.ini
fi

# Create required directories if they don't exist
mkdir -p /var/log/pgbouncer /var/run/pgbouncer

echo "Starting PgBouncer..."
exec "$@"