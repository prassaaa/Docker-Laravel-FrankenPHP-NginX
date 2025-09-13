<?php

/**
 * Laravel Cache Configuration
 * 
 * This file should be placed in your Laravel config directory as config/cache-optimization.php
 * Then include it in your config/cache.php file
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Stores Optimization
    |--------------------------------------------------------------------------
    */
    
    'stores' => [
        // Primary cache store - Redis with optimized settings
        'redis_optimized' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
        
        // Tagged cache store for better invalidation
        'redis_tagged' => [
            'driver' => 'redis',
            'connection' => 'cache_tagged',
        ],
        
        // Session cache store
        'redis_sessions' => [
            'driver' => 'redis',
            'connection' => 'sessions',
        ],
        
        // Queue cache store
        'redis_queue' => [
            'driver' => 'redis',
            'connection' => 'queue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefixes
    |--------------------------------------------------------------------------
    */
    
    'prefixes' => [
        'routes' => 'routes:',
        'config' => 'config:',
        'views' => 'views:',
        'queries' => 'queries:',
        'api' => 'api:',
        'models' => 'models:',
        'user' => 'user:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Settings (in seconds)
    |--------------------------------------------------------------------------
    */
    
    'ttl' => [
        'default' => 3600,              // 1 hour
        'routes' => 86400,              // 24 hours
        'config' => 86400,              // 24 hours
        'views' => 3600,                // 1 hour
        'queries' => 300,               // 5 minutes
        'api_response' => 60,           // 1 minute
        'user_data' => 1800,            // 30 minutes
        'static_content' => 604800,     // 1 week
        'model_cache' => 3600,          // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Tags (for Redis and Memcached)
    |--------------------------------------------------------------------------
    */
    
    'tags' => [
        'enable' => true,
        'groups' => [
            'models' => ['users', 'posts', 'comments'],
            'api' => ['v1', 'v2', 'public', 'private'],
            'views' => ['layouts', 'components', 'pages'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Warming Settings
    |--------------------------------------------------------------------------
    */
    
    'warming' => [
        'enabled' => env('CACHE_WARMING_ENABLED', true),
        'on_deploy' => true,
        'scheduled' => true,
        'schedule' => '0 */6 * * *', // Every 6 hours
        'items' => [
            'routes' => true,
            'config' => true,
            'views' => true,
            'popular_queries' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Caching Settings
    |--------------------------------------------------------------------------
    */
    
    'query_cache' => [
        'enabled' => env('QUERY_CACHE_ENABLED', true),
        'default_ttl' => 300,
        'allowed_tables' => [
            'users', 'posts', 'categories', 'tags', 'settings'
        ],
        'excluded_tables' => [
            'password_resets', 'jobs', 'failed_jobs', 'sessions'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Cache Settings
    |--------------------------------------------------------------------------
    */
    
    'http' => [
        'etag' => true,
        'last_modified' => true,
        'cache_control' => [
            'public' => true,
            'max_age' => 3600,
            's_maxage' => 3600,
            'must_revalidate' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    */
    
    'monitoring' => [
        'track_hit_rate' => true,
        'track_miss_rate' => true,
        'log_slow_operations' => true,
        'slow_operation_threshold' => 100, // milliseconds
    ],
];