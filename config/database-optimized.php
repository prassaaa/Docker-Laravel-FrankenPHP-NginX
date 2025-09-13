<?php

/**
 * Optimized Database Configuration for Laravel
 * 
 * This configuration is optimized for connection pooling,
 * performance, and reliability.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'pgbouncer'),
            'port' => env('DB_PORT', '6432'), // PgBouncer port
            'database' => env('DB_DATABASE', 'laravel_db'),
            'username' => env('DB_USERNAME', 'laravel'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
            
            // Connection pooling optimizations
            'options' => [
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
            
            // Sticky option for read/write connections
            'sticky' => env('DB_STICKY', true),
        ],

        'pgsql_direct' => [
            // Direct connection bypassing PgBouncer for migrations
            'driver' => 'pgsql',
            'host' => env('DB_HOST_DIRECT', 'postgres'),
            'port' => env('DB_PORT_DIRECT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'laravel'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'proxysql'),
            'port' => env('DB_PORT', '6033'), // ProxySQL port
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'laravel'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            
            // Connection optimizations
            'options' => [
                PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ],
            
            // Connection pool settings
            'pool' => [
                'min' => 5,
                'max' => 20,
            ],
            
            'sticky' => env('DB_STICKY', true),
        ],

        'mysql_direct' => [
            // Direct connection bypassing ProxySQL for migrations
            'driver' => 'mysql',
            'host' => env('DB_HOST_DIRECT', 'mysql'),
            'port' => env('DB_PORT_DIRECT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'laravel'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Connections for Monitoring
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Migration Configuration
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
        // Use direct connection for migrations
        'connection' => env('DB_MIGRATION_CONNECTION', 'pgsql_direct'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'default' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'read_timeout' => 60,
            'context' => [
                'stream' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ],
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60,
            ],
        ],

        'cache' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'read_timeout' => 60,
        ],

        'sessions' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
            'read_timeout' => 60,
        ],

        'queue' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '3'),
            'read_timeout' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Retry Configuration
    |--------------------------------------------------------------------------
    */

    'retry' => [
        'times' => env('DB_RETRY_TIMES', 3),
        'sleep' => env('DB_RETRY_SLEEP', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Logging Configuration
    |--------------------------------------------------------------------------
    */

    'log_queries' => env('DB_LOG_QUERIES', false),
    'log_slow_queries' => env('DB_LOG_SLOW_QUERIES', true),
    'slow_query_time' => env('DB_SLOW_QUERY_TIME', 2), // seconds

];