<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class QueryCacheService
{
    /**
     * Cache query results.
     *
     * @param QueryBuilder|EloquentBuilder $query
     * @param string $key
     * @param int $ttl
     * @param array $tags
     * @return mixed
     */
    public function remember($query, string $key, int $ttl = 3600, array $tags = [])
    {
        $cacheKey = $this->generateCacheKey($key, $query);
        
        return Cache::tags(array_merge(['query-cache'], $tags))->remember($cacheKey, $ttl, function () use ($query) {
            // Log query cache miss
            Log::debug('Query cache miss', [
                'key' => $cacheKey,
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings()
            ]);
            
            return $query->get();
        });
    }

    /**
     * Cache query results forever.
     *
     * @param QueryBuilder|EloquentBuilder $query
     * @param string $key
     * @param array $tags
     * @return mixed
     */
    public function rememberForever($query, string $key, array $tags = [])
    {
        $cacheKey = $this->generateCacheKey($key, $query);
        
        return Cache::tags(array_merge(['query-cache'], $tags))->rememberForever($cacheKey, function () use ($query) {
            return $query->get();
        });
    }

    /**
     * Invalidate query cache.
     *
     * @param array|string $tags
     * @return bool
     */
    public function invalidate($tags): bool
    {
        if (!is_array($tags)) {
            $tags = [$tags];
        }
        
        Cache::tags(array_merge(['query-cache'], $tags))->flush();
        
        Log::info('Query cache invalidated', ['tags' => $tags]);
        
        return true;
    }

    /**
     * Invalidate all query cache.
     *
     * @return bool
     */
    public function invalidateAll(): bool
    {
        Cache::tags('query-cache')->flush();
        
        Log::info('All query cache invalidated');
        
        return true;
    }

    /**
     * Generate cache key for query.
     *
     * @param string $key
     * @param QueryBuilder|EloquentBuilder $query
     * @return string
     */
    protected function generateCacheKey(string $key, $query): string
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        
        // Include connection name in cache key
        $connection = $query->getConnection()->getName();
        
        // Generate unique hash
        $hash = md5($connection . $sql . serialize($bindings));
        
        return "query:{$key}:{$hash}";
    }

    /**
     * Cache aggregated results.
     *
     * @param string $table
     * @param string $operation
     * @param string|null $column
     * @param array $conditions
     * @param int $ttl
     * @return mixed
     */
    public function cacheAggregate(string $table, string $operation, ?string $column = null, array $conditions = [], int $ttl = 3600)
    {
        $cacheKey = "aggregate:{$table}:{$operation}";
        
        if ($column) {
            $cacheKey .= ":{$column}";
        }
        
        if (!empty($conditions)) {
            $cacheKey .= ':' . md5(serialize($conditions));
        }
        
        return Cache::tags(['query-cache', 'aggregate', $table])->remember($cacheKey, $ttl, function () use ($table, $operation, $column, $conditions) {
            $query = DB::table($table);
            
            foreach ($conditions as $field => $value) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }
            
            switch ($operation) {
                case 'count':
                    return $query->count($column ?: '*');
                case 'sum':
                    return $query->sum($column);
                case 'avg':
                    return $query->avg($column);
                case 'min':
                    return $query->min($column);
                case 'max':
                    return $query->max($column);
                default:
                    throw new \InvalidArgumentException("Unknown aggregate operation: {$operation}");
            }
        });
    }

    /**
     * Warm up cache for common queries.
     *
     * @param array $queries
     * @return int
     */
    public function warmUp(array $queries): int
    {
        $warmed = 0;
        
        foreach ($queries as $queryConfig) {
            try {
                $model = $queryConfig['model'] ?? null;
                $method = $queryConfig['method'] ?? 'get';
                $conditions = $queryConfig['conditions'] ?? [];
                $with = $queryConfig['with'] ?? [];
                $key = $queryConfig['key'];
                $ttl = $queryConfig['ttl'] ?? 3600;
                $tags = $queryConfig['tags'] ?? [];
                
                if ($model) {
                    $query = $model::query();
                    
                    // Apply eager loading
                    if (!empty($with)) {
                        $query->with($with);
                    }
                    
                    // Apply conditions
                    foreach ($conditions as $condition) {
                        if (count($condition) === 3) {
                            $query->where($condition[0], $condition[1], $condition[2]);
                        } else {
                            $query->where($condition[0], $condition[1]);
                        }
                    }
                    
                    // Cache the results
                    $this->remember($query, $key, $ttl, $tags);
                    $warmed++;
                    
                    Log::info('Query cache warmed', [
                        'key' => $key,
                        'model' => $model,
                        'method' => $method
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to warm query cache', [
                    'key' => $queryConfig['key'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $warmed;
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function getStats(): array
    {
        $stats = [
            'total_queries' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_rate' => 0,
            'memory_usage' => 0,
            'tables' => []
        ];
        
        // Get Redis stats if using Redis cache
        if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
            $redis = Cache::getStore()->getRedis();
            $info = $redis->info();
            
            $stats['memory_usage'] = $info['used_memory_human'] ?? 'N/A';
            
            // Get keys by pattern
            $keys = $redis->keys('laravel_cache:query:*');
            $stats['total_queries'] = count($keys);
            
            // Analyze keys by table
            foreach ($keys as $key) {
                if (preg_match('/query:([^:]+):/', $key, $matches)) {
                    $table = $matches[1];
                    if (!isset($stats['tables'][$table])) {
                        $stats['tables'][$table] = 0;
                    }
                    $stats['tables'][$table]++;
                }
            }
        }
        
        return $stats;
    }

    /**
     * Enable query result caching for a model.
     *
     * @param Model $model
     * @param int $ttl
     * @param array $tags
     * @return void
     */
    public function enableForModel(Model $model, int $ttl = 3600, array $tags = []): void
    {
        $modelClass = get_class($model);
        $table = $model->getTable();
        
        // Add model observer for automatic cache invalidation
        $modelClass::saved(function ($model) use ($table, $tags) {
            $this->invalidate(array_merge([$table], $tags));
        });
        
        $modelClass::deleted(function ($model) use ($table, $tags) {
            $this->invalidate(array_merge([$table], $tags));
        });
        
        Log::info('Query caching enabled for model', [
            'model' => $modelClass,
            'table' => $table,
            'ttl' => $ttl
        ]);
    }
}