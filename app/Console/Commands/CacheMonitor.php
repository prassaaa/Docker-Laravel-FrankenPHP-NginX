<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class CacheMonitor extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:monitor 
                            {--interval=5 : Refresh interval in seconds}
                            {--export : Export stats to JSON file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor cache performance and statistics';

    /**
     * Cache statistics
     *
     * @var array
     */
    protected $stats = [];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $interval = $this->option('interval');
        $export = $this->option('export');

        $this->info('Starting cache monitor...');
        $this->line('Press Ctrl+C to stop');
        $this->line('');

        while (true) {
            $this->collectStats();
            $this->displayStats();

            if ($export) {
                $this->exportStats();
            }

            if ($interval > 0) {
                sleep($interval);
            } else {
                break;
            }
        }

        return 0;
    }

    /**
     * Collect cache statistics
     */
    protected function collectStats()
    {
        $this->stats = [
            'timestamp' => Carbon::now()->toIso8601String(),
            'redis' => $this->getRedisStats(),
            'opcache' => $this->getOpcacheStats(),
            'cache_usage' => $this->getCacheUsageStats(),
            'hit_rates' => $this->getHitRateStats(),
        ];
    }

    /**
     * Get Redis statistics
     *
     * @return array
     */
    protected function getRedisStats()
    {
        try {
            $info = Redis::info();
            
            return [
                'used_memory' => $info['used_memory_human'] ?? 'N/A',
                'used_memory_peak' => $info['used_memory_peak_human'] ?? 'N/A',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                'instantaneous_ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate(
                    $info['keyspace_hits'] ?? 0,
                    $info['keyspace_misses'] ?? 0
                ),
                'evicted_keys' => $info['evicted_keys'] ?? 0,
                'expired_keys' => $info['expired_keys'] ?? 0,
            ];
        } catch (\Exception $e) {
            return ['error' => 'Redis not available'];
        }
    }

    /**
     * Get OPcache statistics
     *
     * @return array
     */
    protected function getOpcacheStats()
    {
        if (!function_exists('opcache_get_status')) {
            return ['error' => 'OPcache not available'];
        }

        $status = opcache_get_status();
        
        if (!$status) {
            return ['error' => 'OPcache disabled'];
        }

        return [
            'enabled' => $status['opcache_enabled'] ?? false,
            'memory_usage' => [
                'used' => $this->formatBytes($status['memory_usage']['used_memory'] ?? 0),
                'free' => $this->formatBytes($status['memory_usage']['free_memory'] ?? 0),
                'wasted' => $this->formatBytes($status['memory_usage']['wasted_memory'] ?? 0),
            ],
            'statistics' => [
                'num_cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
                'hits' => $status['opcache_statistics']['hits'] ?? 0,
                'misses' => $status['opcache_statistics']['misses'] ?? 0,
                'hit_rate' => round($status['opcache_statistics']['opcache_hit_rate'] ?? 0, 2) . '%',
            ],
            'jit' => [
                'enabled' => $status['jit']['enabled'] ?? false,
                'buffer_size' => $this->formatBytes($status['jit']['buffer_size'] ?? 0),
            ],
        ];
    }

    /**
     * Get cache usage statistics
     *
     * @return array
     */
    protected function getCacheUsageStats()
    {
        $stats = [];
        
        // Get cache keys by pattern
        $patterns = [
            'http:*' => 'HTTP Cache',
            'api:*' => 'API Cache',
            'query:*' => 'Query Cache',
            'route:*' => 'Route Cache',
            'view:*' => 'View Cache',
            'model:*' => 'Model Cache',
        ];

        foreach ($patterns as $pattern => $label) {
            $keys = Redis::keys($pattern);
            $stats[$label] = count($keys);
        }

        return $stats;
    }

    /**
     * Get hit rate statistics
     *
     * @return array
     */
    protected function getHitRateStats()
    {
        // This would typically integrate with your cache tracking
        // For now, returning sample data
        return [
            'last_hour' => $this->getHourlyHitRate(),
            'last_day' => $this->getDailyHitRate(),
        ];
    }

    /**
     * Display statistics
     */
    protected function displayStats()
    {
        $this->output->write("\033[2J\033[H"); // Clear screen
        
        $this->info('=== Laravel Cache Monitor ===');
        $this->line('Time: ' . $this->stats['timestamp']);
        $this->line('');

        // Redis Stats
        $this->comment('Redis Statistics:');
        $redis = $this->stats['redis'];
        if (isset($redis['error'])) {
            $this->error('  ' . $redis['error']);
        } else {
            $this->line('  Memory Used: ' . $redis['used_memory']);
            $this->line('  Memory Peak: ' . $redis['used_memory_peak']);
            $this->line('  Connected Clients: ' . $redis['connected_clients']);
            $this->line('  Ops/sec: ' . $redis['instantaneous_ops_per_sec']);
            $this->line('  Hit Rate: ' . $redis['hit_rate'] . '%');
            $this->line('  Evicted Keys: ' . $redis['evicted_keys']);
        }
        $this->line('');

        // OPcache Stats
        $this->comment('OPcache Statistics:');
        $opcache = $this->stats['opcache'];
        if (isset($opcache['error'])) {
            $this->error('  ' . $opcache['error']);
        } else {
            $this->line('  Status: ' . ($opcache['enabled'] ? 'Enabled' : 'Disabled'));
            $this->line('  Memory Used: ' . $opcache['memory_usage']['used']);
            $this->line('  Memory Free: ' . $opcache['memory_usage']['free']);
            $this->line('  Cached Scripts: ' . $opcache['statistics']['num_cached_scripts']);
            $this->line('  Hit Rate: ' . $opcache['statistics']['hit_rate']);
            if ($opcache['jit']['enabled']) {
                $this->line('  JIT Buffer: ' . $opcache['jit']['buffer_size']);
            }
        }
        $this->line('');

        // Cache Usage
        $this->comment('Cache Usage by Type:');
        foreach ($this->stats['cache_usage'] as $type => $count) {
            $this->line('  ' . str_pad($type . ':', 15) . $count . ' keys');
        }
        $this->line('');

        // Performance Summary
        $this->comment('Performance Summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Keys', array_sum($this->stats['cache_usage'])],
                ['Redis Hit Rate', $redis['hit_rate'] . '%' ?? 'N/A'],
                ['OPcache Hit Rate', $opcache['statistics']['hit_rate'] ?? 'N/A'],
            ]
        );
    }

    /**
     * Export statistics to JSON
     */
    protected function exportStats()
    {
        $filename = 'cache-stats-' . Carbon::now()->format('Y-m-d-H-i-s') . '.json';
        $path = storage_path('logs/' . $filename);
        
        file_put_contents($path, json_encode($this->stats, JSON_PRETTY_PRINT));
        
        $this->info('Stats exported to: ' . $path);
    }

    /**
     * Calculate hit rate percentage
     *
     * @param int $hits
     * @param int $misses
     * @return float
     */
    protected function calculateHitRate($hits, $misses)
    {
        $total = $hits + $misses;
        
        if ($total === 0) {
            return 0;
        }
        
        return round(($hits / $total) * 100, 2);
    }

    /**
     * Format bytes to human readable
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Get hourly hit rate (placeholder)
     *
     * @return float
     */
    protected function getHourlyHitRate()
    {
        // This would typically query your tracking data
        return 95.5;
    }

    /**
     * Get daily hit rate (placeholder)
     *
     * @return float
     */
    protected function getDailyHitRate()
    {
        // This would typically query your tracking data
        return 94.2;
    }
}