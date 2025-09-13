<?php

/**
 * Lightweight health check endpoint for Docker health checks
 * Place this in your Laravel public directory as health.php
 */

// Quick response for basic health check
if ($_SERVER['REQUEST_URI'] === '/health') {
    http_response_code(200);
    header('Content-Type: text/plain');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "OK\n";
    exit(0);
}

// Detailed health check
if ($_SERVER['REQUEST_URI'] === '/health/detail') {
    $checks = [];
    
    // Check PHP
    $checks['php'] = [
        'status' => 'ok',
        'version' => PHP_VERSION
    ];
    
    // Check OPcache
    if (function_exists('opcache_get_status')) {
        $opcache = opcache_get_status(false);
        $checks['opcache'] = [
            'status' => $opcache['opcache_enabled'] ? 'ok' : 'disabled',
            'memory_usage' => $opcache['memory_usage']['used_memory'] ?? 0,
            'hit_rate' => $opcache['opcache_statistics']['opcache_hit_rate'] ?? 0
        ];
    }
    
    // Check database connection (optional)
    try {
        if (class_exists('PDO') && getenv('DB_CONNECTION')) {
            $dsn = sprintf(
                "%s:host=%s;port=%s;dbname=%s",
                getenv('DB_CONNECTION'),
                getenv('DB_HOST'),
                getenv('DB_PORT'),
                getenv('DB_DATABASE')
            );
            $pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
                PDO::ATTR_TIMEOUT => 1,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $checks['database'] = ['status' => 'ok'];
        }
    } catch (Exception $e) {
        $checks['database'] = ['status' => 'error', 'message' => 'Connection failed'];
    }
    
    // Check Redis (optional)
    try {
        if (class_exists('Redis') && getenv('REDIS_HOST')) {
            $redis = new Redis();
            $redis->connect(getenv('REDIS_HOST'), getenv('REDIS_PORT') ?: 6379, 1);
            $checks['redis'] = ['status' => 'ok'];
            $redis->close();
        }
    } catch (Exception $e) {
        $checks['redis'] = ['status' => 'error', 'message' => 'Connection failed'];
    }
    
    // Check disk space
    $freeSpace = disk_free_space('/srv');
    $totalSpace = disk_total_space('/srv');
    $usedPercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;
    
    $checks['disk'] = [
        'status' => $usedPercentage < 90 ? 'ok' : 'warning',
        'used_percentage' => round($usedPercentage, 2)
    ];
    
    // Check memory usage
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    
    $checks['memory'] = [
        'status' => 'ok',
        'current' => round($memoryUsage / 1024 / 1024, 2) . 'MB',
        'limit' => $memoryLimit
    ];
    
    // Overall status
    $overallStatus = 'healthy';
    foreach ($checks as $check) {
        if (isset($check['status']) && $check['status'] === 'error') {
            $overallStatus = 'unhealthy';
            break;
        }
    }
    
    // Response
    http_response_code($overallStatus === 'healthy' ? 200 : 503);
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    echo json_encode([
        'status' => $overallStatus,
        'timestamp' => date('c'),
        'checks' => $checks
    ], JSON_PRETTY_PRINT);
    
    exit(0);
}

// Not a health check endpoint
http_response_code(404);
echo "Not Found\n";