<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait OptimizedQueries
{
    /**
     * Boot the optimized queries trait.
     */
    protected static function bootOptimizedQueries()
    {
        // Add global scope for query optimization
        static::addGlobalScope('optimize', function (Builder $builder) {
            // Enable query buffering for MySQL
            if (config('database.default') === 'mysql') {
                DB::statement('SET SESSION query_cache_type = ON');
            }
        });
    }

    /**
     * Scope for index hint (MySQL specific).
     *
     * @param Builder $query
     * @param string $index
     * @return Builder
     */
    public function scopeUseIndex(Builder $query, string $index): Builder
    {
        $table = $this->getTable();
        
        if (config('database.default') === 'mysql') {
            return $query->from(DB::raw("{$table} USE INDEX({$index})"));
        }
        
        return $query;
    }

    /**
     * Scope for force index (MySQL specific).
     *
     * @param Builder $query
     * @param string $index
     * @return Builder
     */
    public function scopeForceIndex(Builder $query, string $index): Builder
    {
        $table = $this->getTable();
        
        if (config('database.default') === 'mysql') {
            return $query->from(DB::raw("{$table} FORCE INDEX({$index})"));
        }
        
        return $query;
    }

    /**
     * Chunked processing with progress callback.
     *
     * @param int $count
     * @param callable $callback
     * @param callable|null $progressCallback
     * @return bool
     */
    public function chunkWithProgress(int $count, callable $callback, ?callable $progressCallback = null): bool
    {
        $totalProcessed = 0;
        $page = 1;

        do {
            $results = $this->forPage($page, $count)->get();
            $countResults = $results->count();

            if ($countResults == 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $totalProcessed += $countResults;
            
            if ($progressCallback !== null) {
                $progressCallback($totalProcessed, $page);
            }

            unset($results);
            $page++;
        } while ($countResults == $count);

        return true;
    }

    /**
     * Efficient exists check with limit 1.
     *
     * @return bool
     */
    public function existsOptimized(): bool
    {
        return $this->limit(1)->exists();
    }

    /**
     * Count with estimated rows for large tables.
     *
     * @param string $column
     * @return int
     */
    public function countEstimate(string $column = '*'): int
    {
        $table = $this->getTable();
        
        // For PostgreSQL, use reltuples from pg_class
        if (config('database.default') === 'pgsql') {
            $result = DB::select("
                SELECT reltuples::BIGINT AS estimate 
                FROM pg_class 
                WHERE relname = ?
            ", [$table]);
            
            if (!empty($result) && $result[0]->estimate > 0) {
                return (int) $result[0]->estimate;
            }
        }
        
        // For MySQL, use information_schema
        if (config('database.default') === 'mysql') {
            $database = config('database.connections.mysql.database');
            $result = DB::select("
                SELECT TABLE_ROWS AS estimate 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ", [$database, $table]);
            
            if (!empty($result) && $result[0]->estimate > 0) {
                return (int) $result[0]->estimate;
            }
        }
        
        // Fallback to regular count
        return $this->count($column);
    }

    /**
     * Get query execution plan.
     *
     * @return array
     */
    public function explain(): array
    {
        $sql = $this->toSql();
        $bindings = $this->getBindings();
        
        if (config('database.default') === 'pgsql') {
            $explainSql = "EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) " . $sql;
        } elseif (config('database.default') === 'mysql') {
            $explainSql = "EXPLAIN FORMAT=JSON " . $sql;
        } else {
            return ['error' => 'Explain not supported for this database driver'];
        }
        
        try {
            $result = DB::select($explainSql, $bindings);
            
            if (config('database.default') === 'mysql' && isset($result[0]->EXPLAIN)) {
                return json_decode($result[0]->EXPLAIN, true);
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Query explain failed', [
                'sql' => $sql,
                'error' => $e->getMessage()
            ]);
            
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Profile query execution.
     *
     * @return array
     */
    public function profile(): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        // Enable profiling for MySQL
        if (config('database.default') === 'mysql') {
            DB::statement('SET profiling = 1');
        }
        
        // Execute the query
        $results = $this->get();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        $profile = [
            'execution_time' => round(($endTime - $startTime) * 1000, 2) . ' ms',
            'memory_usage' => round(($endMemory - $startMemory) / 1024 / 1024, 2) . ' MB',
            'row_count' => $results->count(),
            'sql' => $this->toSql(),
            'bindings' => $this->getBindings(),
        ];
        
        // Get MySQL profiling data
        if (config('database.default') === 'mysql') {
            $profiles = DB::select('SHOW PROFILES');
            if (!empty($profiles)) {
                $lastQueryId = end($profiles)->Query_ID;
                $profileDetails = DB::select("SHOW PROFILE FOR QUERY {$lastQueryId}");
                $profile['mysql_profile'] = $profileDetails;
            }
            DB::statement('SET profiling = 0');
        }
        
        // Get PostgreSQL statistics
        if (config('database.default') === 'pgsql') {
            $stats = DB::select('SELECT * FROM pg_stat_user_tables WHERE relname = ?', [$this->getModel()->getTable()]);
            if (!empty($stats)) {
                $profile['pg_stats'] = $stats[0];
            }
        }
        
        return $profile;
    }

    /**
     * Batch insert with chunk processing.
     *
     * @param array $data
     * @param int $chunkSize
     * @return int
     */
    public static function insertBatch(array $data, int $chunkSize = 1000): int
    {
        $totalInserted = 0;
        
        foreach (array_chunk($data, $chunkSize) as $chunk) {
            try {
                $inserted = DB::table((new static)->getTable())->insert($chunk);
                if ($inserted) {
                    $totalInserted += count($chunk);
                }
            } catch (\Exception $e) {
                Log::error('Batch insert failed', [
                    'table' => (new static)->getTable(),
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
        
        return $totalInserted;
    }

    /**
     * Update with join for better performance.
     *
     * @param string $joinTable
     * @param string $joinCondition
     * @param array $updates
     * @return int
     */
    public function updateWithJoin(string $joinTable, string $joinCondition, array $updates): int
    {
        $table = $this->getTable();
        
        if (config('database.default') === 'mysql') {
            $sql = "UPDATE {$table} INNER JOIN {$joinTable} ON {$joinCondition} SET ";
            $sets = [];
            foreach ($updates as $column => $value) {
                $sets[] = "{$table}.{$column} = ?";
            }
            $sql .= implode(', ', $sets);
            
            return DB::update($sql, array_values($updates));
        }
        
        // PostgreSQL syntax
        if (config('database.default') === 'pgsql') {
            $sql = "UPDATE {$table} SET ";
            $sets = [];
            foreach ($updates as $column => $value) {
                $sets[] = "{$column} = ?";
            }
            $sql .= implode(', ', $sets);
            $sql .= " FROM {$joinTable} WHERE {$joinCondition}";
            
            return DB::update($sql, array_values($updates));
        }
        
        // Fallback to regular update
        return $this->update($updates);
    }

    /**
     * Lock for update with timeout.
     *
     * @param int $timeout
     * @return Builder
     */
    public function lockForUpdateWithTimeout(int $timeout = 3): Builder
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '{$timeout}s'");
        } elseif (config('database.default') === 'mysql') {
            DB::statement("SET SESSION innodb_lock_wait_timeout = {$timeout}");
        }
        
        return $this->lockForUpdate();
    }
}