<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DatabaseMonitoringService
{
    /**
     * Monitor connection pool metrics.
     *
     * @return array
     */
    public function getConnectionPoolMetrics(): array
    {
        $metrics = [
            'timestamp' => now()->toIso8601String(),
            'pools' => []
        ];

        // PgBouncer metrics
        if ($this->isPgBouncerAvailable()) {
            $metrics['pools']['pgbouncer'] = $this->getPgBouncerMetrics();
        }

        // ProxySQL metrics
        if ($this->isProxySQLAvailable()) {
            $metrics['pools']['proxysql'] = $this->getProxySQLMetrics();
        }

        // Laravel connection metrics
        $metrics['pools']['laravel'] = $this->getLaravelConnectionMetrics();

        return $metrics;
    }

    /**
     * Get PgBouncer metrics.
     *
     * @return array
     */
    protected function getPgBouncerMetrics(): array
    {
        try {
            $connection = DB::connection('pgbouncer_admin');
            
            // Pool statistics
            $pools = $connection->select("SHOW POOLS");
            $stats = $connection->select("SHOW STATS");
            $lists = $connection->select("SHOW LISTS");
            $clients = $connection->select("SHOW CLIENTS");
            $servers = $connection->select("SHOW SERVERS");

            return [
                'pools' => array_map(function ($pool) {
                    return [
                        'database' => $pool->database,
                        'user' => $pool->user,
                        'cl_active' => $pool->cl_active,
                        'cl_waiting' => $pool->cl_waiting,
                        'sv_active' => $pool->sv_active,
                        'sv_idle' => $pool->sv_idle,
                        'sv_used' => $pool->sv_used,
                        'sv_tested' => $pool->sv_tested,
                        'sv_login' => $pool->sv_login,
                        'maxwait' => $pool->maxwait,
                        'pool_mode' => $pool->pool_mode
                    ];
                }, $pools),
                'stats' => array_map(function ($stat) {
                    return [
                        'database' => $stat->database,
                        'total_requests' => $stat->total_requests,
                        'total_received' => $stat->total_received,
                        'total_sent' => $stat->total_sent,
                        'total_query_time' => $stat->total_query_time,
                        'avg_req' => $stat->avg_req,
                        'avg_recv' => $stat->avg_recv,
                        'avg_sent' => $stat->avg_sent,
                        'avg_query' => $stat->avg_query
                    ];
                }, $stats),
                'lists' => current($lists),
                'client_count' => count($clients),
                'server_count' => count($servers)
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get PgBouncer metrics', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get ProxySQL metrics.
     *
     * @return array
     */
    protected function getProxySQLMetrics(): array
    {
        try {
            $connection = DB::connection('proxysql_admin');
            
            // Connection pool stats
            $poolStats = $connection->select("
                SELECT hostgroup, srv_host, srv_port, status, 
                       ConnUsed, ConnFree, ConnOK, ConnERR, 
                       Queries, Bytes_data_sent, Bytes_data_recv, 
                       Latency_us
                FROM stats_mysql_connection_pool
            ");

            // Global stats
            $globalStats = $connection->select("
                SELECT Variable_Name, Variable_Value 
                FROM stats_mysql_global
            ");

            // Query rules stats
            $queryStats = $connection->select("
                SELECT rule_id, hits 
                FROM stats_mysql_query_rules
                ORDER BY hits DESC
                LIMIT 10
            ");

            return [
                'connection_pools' => array_map(function ($stat) {
                    return [
                        'hostgroup' => $stat->hostgroup,
                        'server' => $stat->srv_host . ':' . $stat->srv_port,
                        'status' => $stat->status,
                        'connections_used' => $stat->ConnUsed,
                        'connections_free' => $stat->ConnFree,
                        'connections_ok' => $stat->ConnOK,
                        'connections_error' => $stat->ConnERR,
                        'queries' => $stat->Queries,
                        'bytes_sent' => $stat->Bytes_data_sent,
                        'bytes_received' => $stat->Bytes_data_recv,
                        'latency_us' => $stat->Latency_us
                    ];
                }, $poolStats),
                'global_stats' => collect($globalStats)->pluck('Variable_Value', 'Variable_Name')->toArray(),
                'top_query_rules' => $queryStats
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get ProxySQL metrics', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get Laravel connection metrics.
     *
     * @return array
     */
    protected function getLaravelConnectionMetrics(): array
    {
        $connections = config('database.connections');
        $metrics = [];

        foreach ($connections as $name => $config) {
            if (in_array($name, ['pgbouncer_admin', 'proxysql_admin'])) {
                continue;
            }

            try {
                $connection = DB::connection($name);
                $pdo = $connection->getPdo();
                
                $metrics[$name] = [
                    'driver' => $config['driver'] ?? 'unknown',
                    'connected' => $pdo !== null,
                    'persistent' => $pdo->getAttribute(\PDO::ATTR_PERSISTENT),
                    'server_info' => $pdo->getAttribute(\PDO::ATTR_SERVER_INFO),
                    'server_version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
                    'connection_status' => $pdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS),
                ];

                // Get database-specific metrics
                if ($config['driver'] === 'mysql') {
                    $metrics[$name]['mysql_stats'] = $this->getMySQLStats($connection);
                } elseif ($config['driver'] === 'pgsql') {
                    $metrics[$name]['pgsql_stats'] = $this->getPostgreSQLStats($connection);
                }
            } catch (\Exception $e) {
                $metrics[$name] = [
                    'error' => $e->getMessage(),
                    'connected' => false
                ];
            }
        }

        return $metrics;
    }

    /**
     * Get MySQL specific statistics.
     *
     * @param \Illuminate\Database\Connection $connection
     * @return array
     */
    protected function getMySQLStats($connection): array
    {
        try {
            // Thread statistics
            $threads = $connection->select("SHOW STATUS LIKE 'Threads%'");
            $threadStats = collect($threads)->pluck('Value', 'Variable_name')->toArray();

            // Connection statistics
            $connections = $connection->select("SHOW STATUS LIKE '%connect%'");
            $connectionStats = collect($connections)->pluck('Value', 'Variable_name')->toArray();

            // Query cache statistics
            $queryCache = $connection->select("SHOW STATUS LIKE 'Qcache%'");
            $queryCacheStats = collect($queryCache)->pluck('Value', 'Variable_name')->toArray();

            // InnoDB buffer pool statistics
            $innodb = $connection->select("SHOW STATUS LIKE 'Innodb_buffer_pool%'");
            $innodbStats = collect($innodb)->pluck('Value', 'Variable_name')->toArray();

            return [
                'threads' => $threadStats,
                'connections' => $connectionStats,
                'query_cache' => $queryCacheStats,
                'innodb_buffer_pool' => $innodbStats
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get PostgreSQL specific statistics.
     *
     * @param \Illuminate\Database\Connection $connection
     * @return array
     */
    protected function getPostgreSQLStats($connection): array
    {
        try {
            // Database statistics
            $dbStats = $connection->select("
                SELECT datname, numbackends, xact_commit, xact_rollback, 
                       blks_read, blks_hit, tup_returned, tup_fetched, 
                       tup_inserted, tup_updated, tup_deleted, conflicts, 
                       temp_files, temp_bytes, deadlocks, blk_read_time, 
                       blk_write_time
                FROM pg_stat_database 
                WHERE datname = current_database()
            ");

            // Connection statistics
            $connections = $connection->select("
                SELECT count(*) as total,
                       count(*) FILTER (WHERE state = 'active') as active,
                       count(*) FILTER (WHERE state = 'idle') as idle,
                       count(*) FILTER (WHERE state = 'idle in transaction') as idle_in_transaction,
                       count(*) FILTER (WHERE wait_event IS NOT NULL) as waiting
                FROM pg_stat_activity
                WHERE datname = current_database()
            ");

            // Cache hit ratio
            $cacheHit = $connection->select("
                SELECT 
                    sum(heap_blks_read) as heap_read,
                    sum(heap_blks_hit) as heap_hit,
                    sum(heap_blks_hit) / (sum(heap_blks_hit) + sum(heap_blks_read)) as cache_hit_ratio
                FROM pg_statio_user_tables
            ");

            return [
                'database' => current($dbStats),
                'connections' => current($connections),
                'cache_hit_ratio' => current($cacheHit)->cache_hit_ratio ?? 0
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Monitor query performance.
     *
     * @return array
     */
    public function getQueryPerformanceMetrics(): array
    {
        $metrics = [
            'timestamp' => now()->toIso8601String(),
            'slow_queries' => [],
            'query_stats' => []
        ];

        // Get slow queries from cache
        $slowQueries = Cache::get('monitoring:slow_queries', []);
        $metrics['slow_queries'] = array_slice($slowQueries, -100); // Last 100 slow queries

        // Get query statistics
        if (config('database.default') === 'mysql') {
            $metrics['query_stats'] = $this->getMySQLQueryStats();
        } elseif (config('database.default') === 'pgsql') {
            $metrics['query_stats'] = $this->getPostgreSQLQueryStats();
        }

        return $metrics;
    }

    /**
     * Get MySQL query statistics.
     *
     * @return array
     */
    protected function getMySQLQueryStats(): array
    {
        try {
            $stats = DB::select("
                SELECT 
                    DIGEST_TEXT as query,
                    COUNT_STAR as exec_count,
                    SUM_TIMER_WAIT/1000000000000 as total_time_sec,
                    AVG_TIMER_WAIT/1000000000 as avg_time_ms,
                    MIN_TIMER_WAIT/1000000000 as min_time_ms,
                    MAX_TIMER_WAIT/1000000000 as max_time_ms,
                    SUM_ROWS_SENT as total_rows_sent,
                    SUM_ROWS_EXAMINED as total_rows_examined,
                    FIRST_SEEN,
                    LAST_SEEN
                FROM performance_schema.events_statements_summary_by_digest
                WHERE DIGEST_TEXT IS NOT NULL
                ORDER BY SUM_TIMER_WAIT DESC
                LIMIT 20
            ");

            return array_map(function ($stat) {
                return [
                    'query' => substr($stat->query, 0, 100) . '...',
                    'exec_count' => $stat->exec_count,
                    'total_time_sec' => round($stat->total_time_sec, 2),
                    'avg_time_ms' => round($stat->avg_time_ms, 2),
                    'min_time_ms' => round($stat->min_time_ms, 2),
                    'max_time_ms' => round($stat->max_time_ms, 2),
                    'total_rows_sent' => $stat->total_rows_sent,
                    'total_rows_examined' => $stat->total_rows_examined,
                    'efficiency' => $stat->total_rows_examined > 0 
                        ? round($stat->total_rows_sent / $stat->total_rows_examined * 100, 2) 
                        : 100,
                    'first_seen' => $stat->FIRST_SEEN,
                    'last_seen' => $stat->LAST_SEEN
                ];
            }, $stats);
        } catch (\Exception $e) {
            Log::error('Failed to get MySQL query stats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get PostgreSQL query statistics.
     *
     * @return array
     */
    protected function getPostgreSQLQueryStats(): array
    {
        try {
            // Check if pg_stat_statements is available
            $extensions = DB::select("SELECT * FROM pg_extension WHERE extname = 'pg_stat_statements'");
            
            if (empty($extensions)) {
                return ['error' => 'pg_stat_statements extension not installed'];
            }

            $stats = DB::select("
                SELECT 
                    query,
                    calls,
                    total_exec_time,
                    mean_exec_time,
                    min_exec_time,
                    max_exec_time,
                    stddev_exec_time,
                    rows,
                    shared_blks_hit,
                    shared_blks_read,
                    shared_blks_hit + shared_blks_read as total_blks,
                    temp_blks_read,
                    temp_blks_written
                FROM pg_stat_statements
                WHERE query NOT LIKE '%pg_stat_statements%'
                ORDER BY total_exec_time DESC
                LIMIT 20
            ");

            return array_map(function ($stat) {
                $cacheHitRatio = ($stat->total_blks > 0) 
                    ? round($stat->shared_blks_hit / $stat->total_blks * 100, 2) 
                    : 100;

                return [
                    'query' => substr($stat->query, 0, 100) . '...',
                    'calls' => $stat->calls,
                    'total_time_ms' => round($stat->total_exec_time, 2),
                    'avg_time_ms' => round($stat->mean_exec_time, 2),
                    'min_time_ms' => round($stat->min_exec_time, 2),
                    'max_time_ms' => round($stat->max_exec_time, 2),
                    'stddev_time_ms' => round($stat->stddev_exec_time, 2),
                    'total_rows' => $stat->rows,
                    'avg_rows' => $stat->calls > 0 ? round($stat->rows / $stat->calls, 2) : 0,
                    'cache_hit_ratio' => $cacheHitRatio,
                    'temp_blocks_read' => $stat->temp_blks_read,
                    'temp_blocks_written' => $stat->temp_blks_written
                ];
            }, $stats);
        } catch (\Exception $e) {
            Log::error('Failed to get PostgreSQL query stats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Store metrics in time-series format.
     *
     * @param string $type
     * @param array $metrics
     * @return void
     */
    public function storeMetrics(string $type, array $metrics): void
    {
        $key = "monitoring:metrics:{$type}:" . now()->format('Y-m-d:H:i');
        
        // Store in Redis with 7 days TTL
        Redis::setex($key, 604800, json_encode($metrics));
        
        // Update latest metrics
        Redis::set("monitoring:metrics:{$type}:latest", json_encode($metrics));
    }

    /**
     * Get historical metrics.
     *
     * @param string $type
     * @param int $hours
     * @return array
     */
    public function getHistoricalMetrics(string $type, int $hours = 24): array
    {
        $metrics = [];
        $now = now();
        
        for ($i = 0; $i < $hours * 60; $i++) {
            $time = $now->copy()->subMinutes($i);
            $key = "monitoring:metrics:{$type}:" . $time->format('Y-m-d:H:i');
            
            $data = Redis::get($key);
            if ($data) {
                $metrics[] = json_decode($data, true);
            }
        }
        
        return array_reverse($metrics);
    }

    /**
     * Set up alerts for database metrics.
     *
     * @param array $metrics
     * @return void
     */
    public function checkAlerts(array $metrics): void
    {
        $alerts = [];
        
        // Check connection pool alerts
        if (isset($metrics['pools']['pgbouncer'])) {
            foreach ($metrics['pools']['pgbouncer']['pools'] as $pool) {
                if ($pool['cl_waiting'] > 10) {
                    $alerts[] = [
                        'type' => 'connection_pool',
                        'severity' => 'warning',
                        'message' => "PgBouncer pool {$pool['database']} has {$pool['cl_waiting']} waiting clients"
                    ];
                }
            }
        }
        
        // Check slow query alerts
        if (isset($metrics['query_stats'])) {
            foreach ($metrics['query_stats'] as $stat) {
                if ($stat['avg_time_ms'] > 5000) { // 5 seconds
                    $alerts[] = [
                        'type' => 'slow_query',
                        'severity' => 'warning',
                        'message' => "Query averaging {$stat['avg_time_ms']}ms: " . substr($stat['query'], 0, 50)
                    ];
                }
            }
        }
        
        // Store alerts
        if (!empty($alerts)) {
            $this->storeAlerts($alerts);
        }
    }

    /**
     * Store alerts.
     *
     * @param array $alerts
     * @return void
     */
    protected function storeAlerts(array $alerts): void
    {
        foreach ($alerts as $alert) {
            $alert['timestamp'] = now()->toIso8601String();
            
            // Store in Redis list
            Redis::lpush('monitoring:alerts', json_encode($alert));
            Redis::ltrim('monitoring:alerts', 0, 999); // Keep last 1000 alerts
            
            // Log alert
            Log::warning('Database monitoring alert', $alert);
        }
    }

    /**
     * Get recent alerts.
     *
     * @param int $limit
     * @return array
     */
    public function getRecentAlerts(int $limit = 100): array
    {
        $alerts = Redis::lrange('monitoring:alerts', 0, $limit - 1);
        
        return array_map(function ($alert) {
            return json_decode($alert, true);
        }, $alerts);
    }

    /**
     * Check if PgBouncer is available.
     *
     * @return bool
     */
    protected function isPgBouncerAvailable(): bool
    {
        return config('database.connections.pgbouncer_admin') !== null;
    }

    /**
     * Check if ProxySQL is available.
     *
     * @return bool
     */
    protected function isProxySQLAvailable(): bool
    {
        return config('database.connections.proxysql_admin') !== null;
    }
}