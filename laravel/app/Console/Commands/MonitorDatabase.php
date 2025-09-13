<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DatabaseMonitoringService;

class MonitorDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:monitor 
                            {--connections : Show connection pool metrics}
                            {--queries : Show query performance metrics}
                            {--alerts : Show recent alerts}
                            {--history= : Show historical metrics (hours)}
                            {--live : Live monitoring mode}
                            {--interval=60 : Update interval in seconds for live mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor database performance and connection pools';

    /**
     * The monitoring service instance.
     *
     * @var DatabaseMonitoringService
     */
    protected $monitor;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(DatabaseMonitoringService $monitor)
    {
        parent::__construct();
        $this->monitor = $monitor;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->option('live')) {
            return $this->handleLiveMonitoring();
        }

        if ($this->option('alerts')) {
            return $this->handleAlerts();
        }

        if ($this->option('history')) {
            return $this->handleHistory();
        }

        // Default: show current metrics
        $this->showCurrentMetrics();
        
        return 0;
    }

    /**
     * Show current metrics.
     */
    protected function showCurrentMetrics()
    {
        $this->info('Database Monitoring Dashboard');
        $this->info('=============================');
        $this->info('Timestamp: ' . now()->toDateTimeString());
        $this->newLine();

        if ($this->option('connections') || (!$this->option('queries') && !$this->option('alerts'))) {
            $this->showConnectionMetrics();
        }

        if ($this->option('queries') || (!$this->option('connections') && !$this->option('alerts'))) {
            $this->showQueryMetrics();
        }
    }

    /**
     * Show connection pool metrics.
     */
    protected function showConnectionMetrics()
    {
        $metrics = $this->monitor->getConnectionPoolMetrics();
        
        $this->info('Connection Pool Metrics');
        $this->info('-----------------------');

        // PgBouncer metrics
        if (isset($metrics['pools']['pgbouncer']) && !isset($metrics['pools']['pgbouncer']['error'])) {
            $this->newLine();
            $this->comment('PgBouncer Pools:');
            
            $pools = $metrics['pools']['pgbouncer']['pools'] ?? [];
            if (!empty($pools)) {
                $this->table(
                    ['Database', 'User', 'Active', 'Waiting', 'Server Active', 'Server Idle', 'Pool Mode'],
                    array_map(function ($pool) {
                        return [
                            $pool['database'],
                            $pool['user'],
                            $pool['cl_active'],
                            $pool['cl_waiting'],
                            $pool['sv_active'],
                            $pool['sv_idle'],
                            $pool['pool_mode']
                        ];
                    }, $pools)
                );
            }

            $stats = $metrics['pools']['pgbouncer']['stats'] ?? [];
            if (!empty($stats)) {
                $this->comment('PgBouncer Statistics:');
                $this->table(
                    ['Database', 'Total Requests', 'Avg Query Time', 'Avg Req/s'],
                    array_map(function ($stat) {
                        return [
                            $stat['database'],
                            number_format($stat['total_requests']),
                            round($stat['avg_query'], 2) . ' ms',
                            round($stat['avg_req'], 2)
                        ];
                    }, $stats)
                );
            }
        }

        // ProxySQL metrics
        if (isset($metrics['pools']['proxysql']) && !isset($metrics['pools']['proxysql']['error'])) {
            $this->newLine();
            $this->comment('ProxySQL Connection Pools:');
            
            $pools = $metrics['pools']['proxysql']['connection_pools'] ?? [];
            if (!empty($pools)) {
                $this->table(
                    ['Server', 'Status', 'Used', 'Free', 'OK', 'Error', 'Queries', 'Latency'],
                    array_map(function ($pool) {
                        return [
                            $pool['server'],
                            $pool['status'],
                            $pool['connections_used'],
                            $pool['connections_free'],
                            $pool['connections_ok'],
                            $pool['connections_error'],
                            number_format($pool['queries']),
                            $pool['latency_us'] . ' μs'
                        ];
                    }, $pools)
                );
            }
        }

        // Laravel connections
        $this->newLine();
        $this->comment('Laravel Connections:');
        
        $connections = $metrics['pools']['laravel'] ?? [];
        foreach ($connections as $name => $info) {
            if (isset($info['error'])) {
                $this->error("[$name] Error: " . $info['error']);
            } else {
                $this->info("[$name] {$info['driver']} - Connected: " . ($info['connected'] ? 'Yes' : 'No'));
                
                if (isset($info['mysql_stats'])) {
                    $threads = $info['mysql_stats']['threads'] ?? [];
                    if (isset($threads['Threads_connected'])) {
                        $this->line("  Threads Connected: {$threads['Threads_connected']}");
                        $this->line("  Threads Running: " . ($threads['Threads_running'] ?? 'N/A'));
                    }
                } elseif (isset($info['pgsql_stats'])) {
                    $connStats = $info['pgsql_stats']['connections'] ?? [];
                    if (!empty($connStats)) {
                        $this->line("  Total Connections: " . ($connStats->total ?? 'N/A'));
                        $this->line("  Active: " . ($connStats->active ?? 'N/A'));
                        $this->line("  Idle: " . ($connStats->idle ?? 'N/A'));
                        $this->line("  Cache Hit Ratio: " . round(($info['pgsql_stats']['cache_hit_ratio'] ?? 0) * 100, 2) . '%');
                    }
                }
            }
        }

        // Store metrics
        $this->monitor->storeMetrics('connections', $metrics);
        
        // Check alerts
        $this->monitor->checkAlerts($metrics);
    }

    /**
     * Show query performance metrics.
     */
    protected function showQueryMetrics()
    {
        $metrics = $this->monitor->getQueryPerformanceMetrics();
        
        $this->newLine();
        $this->info('Query Performance Metrics');
        $this->info('-------------------------');

        $queryStats = $metrics['query_stats'] ?? [];
        
        if (empty($queryStats)) {
            $this->warn('No query statistics available.');
        } else {
            $this->table(
                ['Query', 'Calls', 'Avg Time', 'Total Time', 'Efficiency'],
                array_map(function ($stat) {
                    return [
                        substr($stat['query'], 0, 50) . '...',
                        number_format($stat['exec_count'] ?? $stat['calls'] ?? 0),
                        ($stat['avg_time_ms'] ?? 0) . ' ms',
                        ($stat['total_time_sec'] ?? round(($stat['total_time_ms'] ?? 0) / 1000, 2)) . ' s',
                        ($stat['efficiency'] ?? $stat['cache_hit_ratio'] ?? 'N/A') . '%'
                    ];
                }, array_slice($queryStats, 0, 10))
            );
        }

        // Show recent slow queries
        $slowQueries = $metrics['slow_queries'] ?? [];
        if (!empty($slowQueries)) {
            $this->newLine();
            $this->warn('Recent Slow Queries:');
            foreach (array_slice($slowQueries, -5) as $query) {
                $this->line("- [{$query['time']}ms] " . substr($query['sql'], 0, 80) . '...');
            }
        }

        // Store metrics
        $this->monitor->storeMetrics('queries', $metrics);
    }

    /**
     * Handle live monitoring mode.
     *
     * @return int
     */
    protected function handleLiveMonitoring()
    {
        $interval = (int) $this->option('interval');
        
        $this->info('Starting live monitoring... (Press Ctrl+C to stop)');
        $this->info("Update interval: {$interval} seconds");
        $this->newLine();

        while (true) {
            // Clear screen (works on most terminals)
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                system('cls');
            } else {
                system('clear');
            }

            $this->showCurrentMetrics();
            
            sleep($interval);
        }

        return 0;
    }

    /**
     * Handle alerts display.
     *
     * @return int
     */
    protected function handleAlerts()
    {
        $alerts = $this->monitor->getRecentAlerts(50);
        
        $this->info('Recent Database Alerts');
        $this->info('======================');

        if (empty($alerts)) {
            $this->info('No recent alerts.');
        } else {
            $this->table(
                ['Timestamp', 'Type', 'Severity', 'Message'],
                array_map(function ($alert) {
                    return [
                        $alert['timestamp'],
                        $alert['type'],
                        $alert['severity'],
                        $alert['message']
                    ];
                }, $alerts)
            );
        }

        return 0;
    }

    /**
     * Handle historical metrics display.
     *
     * @return int
     */
    protected function handleHistory()
    {
        $hours = (int) $this->option('history');
        
        $this->info("Historical Metrics (Last {$hours} hours)");
        $this->info('=====================================');

        // Get connection metrics history
        if ($this->option('connections')) {
            $this->newLine();
            $this->comment('Connection Pool History:');
            
            $history = $this->monitor->getHistoricalMetrics('connections', $hours);
            $this->displayHistoricalTrend($history, 'connections');
        }

        // Get query metrics history
        if ($this->option('queries')) {
            $this->newLine();
            $this->comment('Query Performance History:');
            
            $history = $this->monitor->getHistoricalMetrics('queries', $hours);
            $this->displayHistoricalTrend($history, 'queries');
        }

        return 0;
    }

    /**
     * Display historical trend.
     *
     * @param array $history
     * @param string $type
     */
    protected function displayHistoricalTrend(array $history, string $type)
    {
        if (empty($history)) {
            $this->warn('No historical data available.');
            return;
        }

        // Sample data points for display
        $sampleRate = max(1, count($history) / 20);
        $sampled = [];
        
        foreach ($history as $index => $data) {
            if ($index % $sampleRate == 0) {
                $sampled[] = $data;
            }
        }

        if ($type === 'connections') {
            $this->table(
                ['Time', 'PgBouncer Active', 'PgBouncer Waiting', 'Laravel Connections'],
                array_map(function ($data) {
                    $pgActive = 0;
                    $pgWaiting = 0;
                    
                    if (isset($data['pools']['pgbouncer']['pools'])) {
                        foreach ($data['pools']['pgbouncer']['pools'] as $pool) {
                            $pgActive += $pool['cl_active'];
                            $pgWaiting += $pool['cl_waiting'];
                        }
                    }
                    
                    $laravelCount = count($data['pools']['laravel'] ?? []);
                    
                    return [
                        date('H:i', strtotime($data['timestamp'])),
                        $pgActive,
                        $pgWaiting,
                        $laravelCount
                    ];
                }, $sampled)
            );
        } elseif ($type === 'queries') {
            $this->table(
                ['Time', 'Slow Queries', 'Avg Query Time (ms)'],
                array_map(function ($data) {
                    $slowCount = count($data['slow_queries'] ?? []);
                    $avgTime = 0;
                    
                    if (isset($data['query_stats']) && !empty($data['query_stats'])) {
                        $times = array_column($data['query_stats'], 'avg_time_ms');
                        $avgTime = !empty($times) ? round(array_sum($times) / count($times), 2) : 0;
                    }
                    
                    return [
                        date('H:i', strtotime($data['timestamp'])),
                        $slowCount,
                        $avgTime
                    ];
                }, $sampled)
            );
        }
    }
}