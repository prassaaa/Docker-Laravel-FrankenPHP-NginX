<?php

/**
 * Laravel Preload Script for OPcache
 * 
 * This script preloads Laravel framework core files into OPcache
 * to improve application startup performance.
 */

// Base path to the Laravel application
$basePath = '/srv';

// Check if vendor directory exists
if (!is_dir($basePath . '/vendor')) {
    echo "Vendor directory not found. Skipping preload.\n";
    return;
}

// List of files/directories to preload
$preloadPaths = [
    // Laravel Framework Core
    '/vendor/laravel/framework/src/Illuminate/Foundation',
    '/vendor/laravel/framework/src/Illuminate/Support',
    '/vendor/laravel/framework/src/Illuminate/Container',
    '/vendor/laravel/framework/src/Illuminate/Database',
    '/vendor/laravel/framework/src/Illuminate/Http',
    '/vendor/laravel/framework/src/Illuminate/Routing',
    '/vendor/laravel/framework/src/Illuminate/Session',
    '/vendor/laravel/framework/src/Illuminate/Validation',
    '/vendor/laravel/framework/src/Illuminate/Events',
    '/vendor/laravel/framework/src/Illuminate/Cache',
    '/vendor/laravel/framework/src/Illuminate/Pipeline',
    '/vendor/laravel/framework/src/Illuminate/Queue',
    '/vendor/laravel/framework/src/Illuminate/Log',
    '/vendor/laravel/framework/src/Illuminate/Auth',
    '/vendor/laravel/framework/src/Illuminate/Hashing',
    '/vendor/laravel/framework/src/Illuminate/Encryption',
    '/vendor/laravel/framework/src/Illuminate/Filesystem',
    '/vendor/laravel/framework/src/Illuminate/View',
    
    // Laravel Octane (if installed)
    '/vendor/laravel/octane/src',
    
    // Symfony Components commonly used by Laravel
    '/vendor/symfony/http-foundation',
    '/vendor/symfony/http-kernel',
    '/vendor/symfony/routing',
    '/vendor/symfony/console',
    '/vendor/symfony/finder',
    '/vendor/symfony/var-dumper',
    
    // Common packages
    '/vendor/nesbot/carbon/src',
    '/vendor/monolog/monolog/src',
    '/vendor/league/flysystem/src',
    '/vendor/guzzlehttp/guzzle/src',
    '/vendor/guzzlehttp/psr7/src',
    
    // PSR interfaces
    '/vendor/psr/container/src',
    '/vendor/psr/http-message/src',
    '/vendor/psr/log/src',
    '/vendor/psr/simple-cache/src',
    
    // Application files
    '/app/Http/Kernel.php',
    '/app/Console/Kernel.php',
    '/app/Exceptions/Handler.php',
    '/app/Providers',
    '/app/Http/Controllers/Controller.php',
    '/app/Models',
    
    // Bootstrap files
    '/bootstrap/app.php',
];

// Files to exclude from preloading
$excludePatterns = [
    '/test/',
    '/tests/',
    '/Test/',
    '/Tests/',
    '.blade.php',
    'config.php',
    'routes.php',
];

// Function to check if file should be excluded
function shouldExclude($file) {
    global $excludePatterns;
    
    foreach ($excludePatterns as $pattern) {
        if (stripos($file, $pattern) !== false) {
            return true;
        }
    }
    
    return false;
}

// Function to preload files recursively
function preloadDirectory($directory) {
    if (!is_dir($directory)) {
        return;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filePath = $file->getRealPath();
            
            if (!shouldExclude($filePath)) {
                try {
                    if (!opcache_is_script_cached($filePath)) {
                        opcache_compile_file($filePath);
                    }
                } catch (Exception $e) {
                    // Ignore files that can't be compiled
                    echo "Failed to preload: " . $filePath . " - " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

// Function to preload a single file
function preloadFile($file) {
    if (is_file($file) && !shouldExclude($file)) {
        try {
            if (!opcache_is_script_cached($file)) {
                opcache_compile_file($file);
            }
        } catch (Exception $e) {
            echo "Failed to preload: " . $file . " - " . $e->getMessage() . "\n";
        }
    }
}

// Start preloading
echo "Starting Laravel preload...\n";

$preloadedCount = 0;
$startTime = microtime(true);

// Preload specified paths
foreach ($preloadPaths as $path) {
    $fullPath = $basePath . $path;
    
    if (is_dir($fullPath)) {
        preloadDirectory($fullPath);
    } elseif (is_file($fullPath)) {
        preloadFile($fullPath);
    }
}

// Preload composer autoload files
$composerAutoloadFiles = [
    $basePath . '/vendor/autoload.php',
    $basePath . '/vendor/composer/autoload_real.php',
    $basePath . '/vendor/composer/autoload_static.php',
    $basePath . '/vendor/composer/ClassLoader.php',
];

foreach ($composerAutoloadFiles as $file) {
    preloadFile($file);
}

// Calculate statistics
$endTime = microtime(true);
$duration = round(($endTime - $startTime) * 1000, 2);

// Get OPcache statistics
$status = opcache_get_status();
$preloadedCount = $status['opcache_statistics']['num_cached_scripts'] ?? 0;
$memoryUsed = round(($status['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2);

echo "Laravel preload completed!\n";
echo "Files preloaded: " . $preloadedCount . "\n";
echo "Memory used: " . $memoryUsed . " MB\n";
echo "Time taken: " . $duration . " ms\n";