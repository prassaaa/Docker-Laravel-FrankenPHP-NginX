<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query Cacheable Trait
 * 
 * Add this trait to your Eloquent models to enable query caching
 */
trait QueryCacheable
{
    /**
     * Boot the trait
     */
    public static function bootQueryCacheable()
    {
        // Clear cache on model events
        static::created(function ($model) {
            $model->flushQueryCache();
        });

        static::updated(function ($model) {
            $model->flushQueryCache();
        });

        static::deleted(function ($model) {
            $model->flushQueryCache();
        });
    }

    /**
     * Cache a query result
     *
     * @param Builder $query
     * @param string $key
     * @param int $ttl
     * @return mixed
     */
    public function scopeCacheFor($query, $ttl = 3600, $key = null)
    {
        $key = $key ?: $this->getCacheKey($query);
        
        return Cache::tags($this->getCacheTags())->remember($key, $ttl, function () use ($query) {
            return $query->get();
        });
    }

    /**
     * Cache query forever
     *
     * @param Builder $query
     * @param string $key
     * @return mixed
     */
    public function scopeCacheForever($query, $key = null)
    {
        $key = $key ?: $this->getCacheKey($query);
        
        return Cache::tags($this->getCacheTags())->rememberForever($key, function () use ($query) {
            return $query->get();
        });
    }

    /**
     * Cache query with tags
     *
     * @param Builder $query
     * @param array $tags
     * @param int $ttl
     * @return mixed
     */
    public function scopeCacheWithTags($query, array $tags, $ttl = 3600)
    {
        $key = $this->getCacheKey($query);
        $tags = array_merge($this->getCacheTags(), $tags);
        
        return Cache::tags($tags)->remember($key, $ttl, function () use ($query) {
            return $query->get();
        });
    }

    /**
     * Generate cache key for query
     *
     * @param Builder $query
     * @return string
     */
    protected function getCacheKey($query)
    {
        return sprintf(
            '%s:%s:%s',
            $this->getCachePrefix(),
            $this->getTable(),
            md5($query->toSql() . serialize($query->getBindings()))
        );
    }

    /**
     * Get cache tags for this model
     *
     * @return array
     */
    protected function getCacheTags()
    {
        return [
            'eloquent',
            class_basename($this),
            $this->getTable(),
        ];
    }

    /**
     * Get cache prefix
     *
     * @return string
     */
    protected function getCachePrefix()
    {
        return config('cache.prefix', 'laravel') . ':query';
    }

    /**
     * Flush query cache for this model
     *
     * @return bool
     */
    public function flushQueryCache()
    {
        return Cache::tags($this->getCacheTags())->flush();
    }

    /**
     * Flush specific cache tags
     *
     * @param array $tags
     * @return bool
     */
    public static function flushCacheTags(array $tags)
    {
        return Cache::tags($tags)->flush();
    }

    /**
     * Get cached count
     *
     * @param int $ttl
     * @return int
     */
    public static function cachedCount($ttl = 3600)
    {
        $instance = new static;
        $key = $instance->getCachePrefix() . ':' . $instance->getTable() . ':count';
        
        return Cache::tags($instance->getCacheTags())->remember($key, $ttl, function () {
            return static::count();
        });
    }

    /**
     * Find model by ID with cache
     *
     * @param mixed $id
     * @param int $ttl
     * @return mixed
     */
    public static function findCached($id, $ttl = 3600)
    {
        $instance = new static;
        $key = $instance->getCachePrefix() . ':' . $instance->getTable() . ':id:' . $id;
        
        return Cache::tags($instance->getCacheTags())->remember($key, $ttl, function () use ($id) {
            return static::find($id);
        });
    }

    /**
     * Get all records with cache
     *
     * @param int $ttl
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function allCached($ttl = 3600)
    {
        $instance = new static;
        $key = $instance->getCachePrefix() . ':' . $instance->getTable() . ':all';
        
        return Cache::tags($instance->getCacheTags())->remember($key, $ttl, function () {
            return static::all();
        });
    }

    /**
     * Paginate with cache
     *
     * @param int $perPage
     * @param int $ttl
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public static function paginateCached($perPage = 15, $ttl = 300)
    {
        $instance = new static;
        $page = request()->get('page', 1);
        $key = $instance->getCachePrefix() . ':' . $instance->getTable() . ':paginate:' . $perPage . ':' . $page;
        
        return Cache::tags($instance->getCacheTags())->remember($key, $ttl, function () use ($perPage) {
            return static::paginate($perPage);
        });
    }

    /**
     * Cache relationship count
     *
     * @param string $relation
     * @param int $ttl
     * @return int
     */
    public function cachedRelationCount($relation, $ttl = 3600)
    {
        $key = $this->getCachePrefix() . ':' . $this->getTable() . ':' . $this->getKey() . ':' . $relation . ':count';
        
        return Cache::tags($this->getCacheTags())->remember($key, $ttl, function () use ($relation) {
            return $this->$relation()->count();
        });
    }

    /**
     * Remember query result
     *
     * @param string $key
     * @param int $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public static function rememberQuery($key, $ttl, \Closure $callback)
    {
        $instance = new static;
        $fullKey = $instance->getCachePrefix() . ':custom:' . $key;
        
        return Cache::tags($instance->getCacheTags())->remember($fullKey, $ttl, $callback);
    }
}