#!/usr/bin/env php
<?php

/**
 * Laravel Cache Warming Script
 * 
 * This script warms up various Laravel caches for optimal performance
 * Place this in your Laravel root directory and run via CLI
 */

// Bootstrap Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;

class CacheWarmer
{
    protected $startTime;
    protected $warmedItems = [];
    
    public function __construct()
    {
        $this->startTime = microtime(true);
    }
    
    /**
     * Run all cache warming operations
     */
    public function warmAll()
    {
        $this->output("Starting cache warming process...\n");
        
        // Laravel optimization commands
        $this->warmLaravelCaches();
        
        // Route cache warming
        $this->warmRouteCache();
        
        // View cache warming
        $this->warmViewCache();
        
        // Database query cache warming
        $this->warmQueryCache();
        
        // API endpoint cache warming
        $this->warmApiCache();
        
        // Static content cache warming
        $this->warmStaticCache();
        
        // Model cache warming
        $this->warmModelCache();
        
        $this->reportResults();
    }
    
    /**
     * Warm Laravel's built-in caches
     */
    protected function warmLaravelCaches()
    {
        $this->output("Warming Laravel caches...");
        
        // Config cache
        if ($this->runArtisan('config:cache')) {
            $this->warmedItems[] = 'Configuration cache';
        }
        
        // Route cache
        if ($this->runArtisan('route:cache')) {
            $this->warmedItems[] = 'Route cache';
        }
        
        // View cache
        if ($this->runArtisan('view:cache')) {
            $this->warmedItems[] = 'View cache';
        }
        
        // Event cache (if available)
        if ($this->runArtisan('event:cache')) {
            $this->warmedItems[] = 'Event cache';
        }
    }
    
    /**
     * Warm route-specific caches
     */
    protected function warmRouteCache()
    {
        $this->output("Warming route caches...");
        
        $routes = Route::getRoutes();
        $cachedRoutes = 0;
        
        foreach ($routes as $route) {
            // Cache route metadata
            $key = 'route:' . md5($route->uri());
            Cache::put($key, [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'action' => $route->getActionName(),
                'middleware' => $route->middleware(),
            ], 86400); // 24 hours
            
            $cachedRoutes++;
        }
        
        $this->warmedItems[] = "Route metadata ({$cachedRoutes} routes)";
    }
    
    /**
     * Warm view caches
     */
    protected function warmViewCache()
    {
        $this->output("Warming view caches...");
        
        $viewsPath = resource_path('views');
        $viewFiles = $this->getViewFiles($viewsPath);
        $cachedViews = 0;
        
        foreach ($viewFiles as $view) {
            try {
                // Compile the view
                View::make($view)->render();
                $cachedViews++;
            } catch (\Exception $e) {
                // Skip views that require data
                continue;
            }
        }
        
        $this->warmedItems[] = "Compiled views ({$cachedViews} views)";
    }
    
    /**
     * Warm frequently accessed database queries
     */
    protected function warmQueryCache()
    {
        $this->output("Warming query caches...");
        
        // Cache user count
        Cache::remember('stats:users:count', 3600, function () {
            return DB::table('users')->count();
        });
        
        // Cache common settings
        if (Schema::hasTable('settings')) {
            Cache::remember('settings:all', 86400, function () {
                return DB::table('settings')->pluck('value', 'key');
            });
        }
        
        // Cache popular posts (example)
        if (Schema::hasTable('posts')) {
            Cache::remember('posts:popular', 3600, function () {
                return DB::table('posts')
                    ->orderBy('views', 'desc')
                    ->limit(10)
                    ->get();
            });
        }
        
        $this->warmedItems[] = "Database query caches";
    }
    
    /**
     * Warm API response caches
     */
    protected function warmApiCache()
    {
        $this->output("Warming API caches...");
        
        // Get API routes
        $apiRoutes = collect(Route::getRoutes())->filter(function ($route) {
            return str_starts_with($route->uri(), 'api/');
        });
        
        $cachedEndpoints = 0;
        
        foreach ($apiRoutes as $route) {
            if (in_array('GET', $route->methods())) {
                $key = 'api:' . md5($route->uri());
                
                // Cache endpoint metadata
                Cache::put($key . ':meta', [
                    'uri' => $route->uri(),
                    'version' => $this->extractApiVersion($route->uri()),
                    'cached_at' => now(),
                ], 3600);
                
                $cachedEndpoints++;
            }
        }
        
        $this->warmedItems[] = "API endpoints ({$cachedEndpoints} endpoints)";
    }
    
    /**
     * Warm static content caches
     */
    protected function warmStaticCache()
    {
        $this->output("Warming static content caches...");
        
        // Cache asset manifest
        if (file_exists(public_path('mix-manifest.json'))) {
            $manifest = json_decode(file_get_contents(public_path('mix-manifest.json')), true);
            Cache::put('assets:manifest', $manifest, 604800); // 1 week
            $this->warmedItems[] = "Asset manifest";
        }
        
        // Cache build version
        if (file_exists(public_path('build/manifest.json'))) {
            $buildManifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
            Cache::put('assets:build:manifest', $buildManifest, 604800);
            $this->warmedItems[] = "Build manifest";
        }
    }
    
    /**
     * Warm model caches
     */
    protected function warmModelCache()
    {
        $this->output("Warming model caches...");
        
        // Cache model counts
        $models = [
            'User' => \App\Models\User::class,
            // Add your models here
        ];
        
        foreach ($models as $name => $class) {
            if (class_exists($class)) {
                Cache::remember("model:{$name}:count", 3600, function () use ($class) {
                    return $class::count();
                });
            }
        }
        
        $this->warmedItems[] = "Model caches";
    }
    
    /**
     * Get all view files recursively
     */
    protected function getViewFiles($path, $prefix = '')
    {
        $views = [];
        $files = scandir($path);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $fullPath = $path . '/' . $file;
            
            if (is_dir($fullPath)) {
                $views = array_merge($views, $this->getViewFiles($fullPath, $prefix . $file . '.'));
            } elseif (str_ends_with($file, '.blade.php')) {
                $viewName = $prefix . str_replace('.blade.php', '', $file);
                $views[] = $viewName;
            }
        }
        
        return $views;
    }
    
    /**
     * Extract API version from URI
     */
    protected function extractApiVersion($uri)
    {
        if (preg_match('/api\/v(\d+)/', $uri, $matches)) {
            return 'v' . $matches[1];
        }
        return 'v1';
    }
    
    /**
     * Run artisan command
     */
    protected function runArtisan($command)
    {
        try {
            Artisan::call($command);
            return true;
        } catch (\Exception $e) {
            $this->output("Failed to run: php artisan {$command}");
            return false;
        }
    }
    
    /**
     * Output message
     */
    protected function output($message)
    {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    }
    
    /**
     * Report warming results
     */
    protected function reportResults()
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        
        $this->output("\nCache warming completed!");
        $this->output("Duration: {$duration} seconds");
        $this->output("\nWarmed items:");
        
        foreach ($this->warmedItems as $item) {
            $this->output("  ✓ {$item}");
        }
        
        // Log results
        Log::info('Cache warming completed', [
            'duration' => $duration,
            'items' => $this->warmedItems,
        ]);
    }
}

// Run the cache warmer
$warmer = new CacheWarmer();
$warmer->warmAll();