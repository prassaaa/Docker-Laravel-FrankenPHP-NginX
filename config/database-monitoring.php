<?php

/**
 * Database Monitoring Configuration
 * 
 * Configuration for monitoring database connections,
 * connection pools, and query performance.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Monitoring Admin Connections
    |--------------------------------------------------------------------------
    |
    | These connections are used to access monitoring interfaces
    | for connection poolers like PgBouncer and ProxySQL.
    |
    */

    'admin_connections' => [

        'pgbouncer_admin' => [
            'driver' => 'pgsql',
            'host' => env('PGBOUNCER_ADMIN_HOST', 'pgbouncer'),
            'port' => env('PGBOUNCER_ADMIN_PORT', '6432'),
            'database' => 'pgbouncer',
            'username' => env('PGBOUNCER_ADMIN_USER', 'pgbouncer'),
            'password' => env('PGBOUNCER_ADMIN_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'proxysql_admin' => [
            'driver' => 'mysql',
            'host' => env('PROXYSQL_ADMIN_HOST', 'proxysql'),
            'port' => env('PROXYSQL_ADMIN_PORT', '6032'),
            'database' => 'main',
            'username' => env('PROXYSQL_ADMIN_USER', 'admin'),
            'password' => env('PROXYSQL_ADMIN_PASSWORD', 'admin'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Settings
    |--------------------------------------------------------------------------
    |
    | Configure monitoring intervals, retention, and alert thresholds.
    |
    */

    'monitoring' => [

        // How often to collect metrics (in minutes)
        'collection_interval' => env('DB_MONITORING_INTERVAL', 1),

        // How long to retain metrics (in days)
        'retention_days' => env('DB_MONITORING_RETENTION', 7),

        // Enable/disable monitoring features
        'enabled' => env('DB_MONITORING_ENABLED', true),
        'collect_connection_metrics' => env('DB_MONITORING_CONNECTIONS', true),
        'collect_query_metrics' => env('DB_MONITORING_QUERIES', true),
        'collect_slow_queries' => env('DB_MONITORING_SLOW_QUERIES', true),

        // Alert thresholds
        'alerts' => [
            // Connection pool alerts
            'connection_pool' => [
                'waiting_clients_threshold' => env('DB_ALERT_WAITING_CLIENTS', 10),
                'connection_error_threshold' => env('DB_ALERT_CONNECTION_ERRORS', 5),
                'pool_exhaustion_percent' => env('DB_ALERT_POOL_EXHAUSTION', 90),
            ],

            // Query performance alerts
            'query_performance' => [
                'slow_query_threshold_ms' => env('DB_ALERT_SLOW_QUERY_MS', 5000),
                'deadlock_threshold' => env('DB_ALERT_DEADLOCKS', 1),
                'lock_wait_threshold_ms' => env('DB_ALERT_LOCK_WAIT_MS', 3000),
            ],

            // Database health alerts
            'database_health' => [
                'cache_hit_ratio_threshold' => env('DB_ALERT_CACHE_HIT_RATIO', 80),
                'connection_count_threshold' => env('DB_ALERT_CONNECTION_COUNT', 100),
                'replication_lag_threshold_s' => env('DB_ALERT_REPLICATION_LAG', 10),
            ],
        ],

        // Notification channels for alerts
        'notifications' => [
            'channels' => env('DB_ALERT_CHANNELS', 'log'), // log, mail, slack
            'recipients' => env('DB_ALERT_RECIPIENTS', ''),
            'throttle_minutes' => env('DB_ALERT_THROTTLE', 60),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Query Analysis Settings
    |--------------------------------------------------------------------------
    |
    | Configure query analysis and optimization features.
    |
    */

    'query_analysis' => [

        // Enable query plan collection
        'collect_query_plans' => env('DB_COLLECT_QUERY_PLANS', false),

        // Queries to monitor specifically
        'monitored_queries' => [
            // Add query patterns to monitor
            // 'users_by_email' => 'SELECT * FROM users WHERE email = ?',
        ],

        // Tables to monitor for optimization
        'monitored_tables' => [
            // 'users', 'posts', 'comments'
        ],

        // Index recommendations
        'index_analysis' => [
            'enabled' => env('DB_INDEX_ANALYSIS', true),
            'min_table_rows' => env('DB_INDEX_MIN_ROWS', 1000),
            'unused_index_days' => env('DB_INDEX_UNUSED_DAYS', 30),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Tuning Recommendations
    |--------------------------------------------------------------------------
    |
    | Automated performance tuning recommendations based on metrics.
    |
    */

    'tuning' => [

        // Connection pool tuning
        'connection_pools' => [
            'auto_adjust' => env('DB_POOL_AUTO_ADJUST', false),
            'min_pool_size' => env('DB_POOL_MIN_SIZE', 10),
            'max_pool_size' => env('DB_POOL_MAX_SIZE', 100),
            'target_utilization' => env('DB_POOL_TARGET_UTIL', 75),
        ],

        // Query cache tuning
        'query_cache' => [
            'auto_warm' => env('DB_CACHE_AUTO_WARM', true),
            'warm_queries' => [
                // Define queries to warm on startup
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Export
    |--------------------------------------------------------------------------
    |
    | Configure metrics export for external monitoring systems.
    |
    */

    'export' => [

        // Prometheus metrics
        'prometheus' => [
            'enabled' => env('DB_METRICS_PROMETHEUS', false),
            'endpoint' => env('DB_METRICS_ENDPOINT', '/metrics/database'),
            'labels' => [
                'app' => env('APP_NAME', 'laravel'),
                'env' => env('APP_ENV', 'production'),
            ],
        ],

        // StatsD metrics
        'statsd' => [
            'enabled' => env('DB_METRICS_STATSD', false),
            'host' => env('STATSD_HOST', 'localhost'),
            'port' => env('STATSD_PORT', 8125),
            'prefix' => env('STATSD_PREFIX', 'laravel.db'),
        ],

    ],

];