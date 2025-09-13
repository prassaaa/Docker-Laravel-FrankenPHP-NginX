<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

class CacheManage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:manage 
                            {action : Action to perform (clear, warm, flush, status)}
                            {--tag=* : Cache tags to clear}
                            {--type=* : Cache types to manage}
                            {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage application cache';

    /**
     * Cache types and their handlers
     *
     * @var array
     */
    protected $cacheTypes = [
        'config' => 'Configuration Cache',
        'route' => 'Route Cache',
        'view' => 'View Cache',
        'event' => 'Event Cache',
        'query' => 'Query Cache',
        'http' => 'HTTP Response Cache',
        'api' => 'API Response Cache',
        'model' => 'Model Cache',
        'all' => 'All Caches',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');
        
        switch ($action) {
            case 'clear':
                return $this->handleClear();
            case 'warm':
                return $this->handleWarm();
            case 'flush':
                return $this->handleFlush();
            case 'status':
                return $this->handleStatus();
            default:
                $this->error("Unknown action: {$action}");
                return 1;
        }
    }

    /**
     * Handle cache clear operation
     *
     * @return int
     */
    protected function handleClear()
    {
        $types = $this->option('type') ?: ['all'];
        $tags = $this->option('tag');
        $force = $this->option('force');

        if (!$force) {
            $typesStr = implode(', ', $types);
            if (!$this->confirm("Clear cache for: {$typesStr}?")) {
                return 0;
            }
        }

        foreach ($types as $type) {
            $this->clearCacheType($type, $tags);
        }

        $this->info('Cache cleared successfully!');
        return 0;
    }

    /**
     * Clear specific cache type
     *
     * @param string $type
     * @param array $tags
     */
    protected function clearCacheType($type, $tags = [])
    {
        $this->info("Clearing {$this->cacheTypes[$type] ?? $type}...");

        switch ($type) {
            case 'config':
                Artisan::call('config:clear');
                break;
                
            case 'route':
                Artisan::call('route:clear');
                break;
                
            case 'view':
                Artisan::call('view:clear');
                break;
                
            case 'event':
                Artisan::call('event:clear');
                break;
                
            case 'query':
                Cache::tags(['eloquent', 'query'])->flush();
                break;
                
            case 'http':
                Cache::tags(['http', 'responses'])->flush();
                break;
                
            case 'api':
                Cache::tags(['api'])->flush();
                break;
                
            case 'model':
                Cache::tags(['eloquent'])->flush();
                break;
                
            case 'all':
                Artisan::call('cache:clear');
                $this->clearLaravelCaches();
                break;
                
            default:
                if ($tags) {
                    Cache::tags($tags)->flush();
                }
        }
    }

    /**
     * Clear all Laravel caches
     */
    protected function clearLaravelCaches()
    {
        $commands = [
            'config:clear',
            'route:clear',
            'view:clear',
            'event:clear',
        ];

        foreach ($commands as $command) {
            try {
                Artisan::call($command);
            } catch (\Exception $e) {
                // Command might not exist
            }
        }
    }

    /**
     * Handle cache warm operation
     *
     * @return int
     */
    protected function handleWarm()
    {
        $types = $this->option('type') ?: ['all'];

        $this->info('Warming cache...');
        $this->line('');

        foreach ($types as $type) {
            $this->warmCacheType($type);
        }

        // Run cache warming script
        if (in_array('all', $types)) {
            $this->info('Running comprehensive cache warming...');
            $warmScript = base_path('docker/php/config/cache-warm.php');
            if (file_exists($warmScript)) {
                passthru("php {$warmScript}");
            }
        }

        $this->info('Cache warming completed!');
        return 0;
    }

    /**
     * Warm specific cache type
     *
     * @param string $type
     */
    protected function warmCacheType($type)
    {
        $this->info("Warming {$this->cacheTypes[$type] ?? $type}...");

        switch ($type) {
            case 'config':
                Artisan::call('config:cache');
                break;
                
            case 'route':
                Artisan::call('route:cache');
                break;
                
            case 'view':
                Artisan::call('view:cache');
                break;
                
            case 'event':
                Artisan::call('event:cache');
                break;
                
            case 'all':
                $this->warmAllCaches();
                break;
        }
    }

    /**
     * Warm all caches
     */
    protected function warmAllCaches()
    {
        $commands = [
            'config:cache',
            'route:cache',
            'view:cache',
            'event:cache',
        ];

        foreach ($commands as $command) {
            try {
                Artisan::call($command);
                $this->line("  ✓ {$command}");
            } catch (\Exception $e) {
                $this->line("  ✗ {$command}: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle cache flush operation
     *
     * @return int
     */
    protected function handleFlush()
    {
        $force = $this->option('force');

        if (!$force && !$this->confirm('This will flush ALL cache data. Are you sure?')) {
            return 0;
        }

        $this->info('Flushing all cache...');
        
        Cache::flush();
        $this->clearLaravelCaches();
        
        // Clear Redis if available
        try {
            Artisan::call('redis:clear');
        } catch (\Exception $e) {
            // Redis might not be configured
        }

        $this->info('All cache flushed!');
        return 0;
    }

    /**
     * Handle cache status operation
     *
     * @return int
     */
    protected function handleStatus()
    {
        $this->info('Cache Status');
        $this->line('');

        // Laravel cache status
        $this->comment('Laravel Caches:');
        $this->checkLaravelCaches();
        $this->line('');

        // Cache statistics
        $this->comment('Cache Statistics:');
        $this->displayCacheStats();
        $this->line('');

        // Redis status
        $this->comment('Redis Status:');
        $this->displayRedisStatus();

        return 0;
    }

    /**
     * Check Laravel cache status
     */
    protected function checkLaravelCaches()
    {
        $caches = [
            'config' => $this->isConfigCached(),
            'route' => $this->isRouteCached(),
            'view' => $this->isViewCached(),
            'event' => $this->isEventCached(),
        ];

        foreach ($caches as $type => $cached) {
            $status = $cached ? '✓ Cached' : '✗ Not Cached';
            $color = $cached ? 'info' : 'comment';
            $this->$color("  {$type}: {$status}");
        }
    }

    /**
     * Display cache statistics
     */
    protected function displayCacheStats()
    {
        try {
            $stats = [
                'Default Store' => config('cache.default'),
                'Cache Prefix' => config('cache.prefix'),
            ];

            foreach ($stats as $label => $value) {
                $this->line("  {$label}: {$value}");
            }
        } catch (\Exception $e) {
            $this->error('  Unable to retrieve cache statistics');
        }
    }

    /**
     * Display Redis status
     */
    protected function displayRedisStatus()
    {
        try {
            $redis = app('redis');
            $info = $redis->info();
            
            $this->line('  Connected: ✓');
            $this->line('  Memory Used: ' . ($info['used_memory_human'] ?? 'N/A'));
            $this->line('  Connected Clients: ' . ($info['connected_clients'] ?? 'N/A'));
            $this->line('  Total Keys: ' . $this->countRedisKeys());
        } catch (\Exception $e) {
            $this->error('  Redis not available');
        }
    }

    /**
     * Count Redis keys
     *
     * @return int
     */
    protected function countRedisKeys()
    {
        try {
            $redis = app('redis');
            $keys = $redis->keys('*');
            return count($keys);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Check if config is cached
     *
     * @return bool
     */
    protected function isConfigCached()
    {
        return file_exists(base_path('bootstrap/cache/config.php'));
    }

    /**
     * Check if routes are cached
     *
     * @return bool
     */
    protected function isRouteCached()
    {
        return file_exists(base_path('bootstrap/cache/routes-v7.php'));
    }

    /**
     * Check if views are cached
     *
     * @return bool
     */
    protected function isViewCached()
    {
        $viewPath = config('view.compiled');
        return is_dir($viewPath) && count(glob("{$viewPath}/*.php")) > 0;
    }

    /**
     * Check if events are cached
     *
     * @return bool
     */
    protected function isEventCached()
    {
        return file_exists(base_path('bootstrap/cache/events.php'));
    }
}