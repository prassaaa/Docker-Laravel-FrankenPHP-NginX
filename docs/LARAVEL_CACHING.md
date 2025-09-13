# Laravel Caching Optimizations (Phase 4)

This document outlines the Laravel-specific caching optimizations implemented in Phase 4.

## Overview

The optimizations focus on:
- Redis configuration for Laravel
- Cache warming strategies
- Query result caching
- HTTP response caching
- API response caching with ETags
- Cache monitoring and management tools

## Implemented Components

### 1. Redis Configuration

**Configuration file**: `docker/redis/redis.conf`
- Optimized for Laravel's caching needs
- 512MB memory limit with LRU eviction
- Persistence enabled for sessions/queues
- Thread I/O enabled (4 threads)
- Active defragmentation
- Security hardening (dangerous commands disabled)

**Docker service added**:
```yaml
redis:
  image: redis:7-alpine
  volumes:
    - ./docker/redis/redis.conf:/usr/local/etc/redis/redis.conf:ro
    - redis_data:/data
  deploy:
    resources:
      limits: 1 CPU, 512MB memory
```

### 2. Cache Warming

**Script**: `docker/php/config/cache-warm.php`
- Warms Laravel config, route, view caches
- Pre-caches popular queries
- Caches route metadata
- Compiles views
- Warms API endpoint metadata

**Usage**:
```bash
php cache-warm.php
```

### 3. Query Caching

**Trait**: `app/Traits/QueryCacheable.php`

Add to your models:
```php
use App\Traits\QueryCacheable;

class User extends Model
{
    use QueryCacheable;
}
```

**Features**:
- Cache query results with tags
- Automatic cache invalidation on model events
- Helper methods for common queries
- Support for pagination caching

**Usage examples**:
```php
// Cache for 1 hour
User::where('active', true)->cacheFor(3600)->get();

// Cache forever with tags
Post::with('comments')->cacheWithTags(['posts', 'homepage'])->get();

// Cached pagination
Post::paginateCached(20, 300); // 20 per page, 5 min cache

// Find with cache
User::findCached($id, 3600);
```

### 4. HTTP Response Caching

**Middleware**: `app/Http/Middleware/HttpCache.php`

**Features**:
- ETags generation
- 304 Not Modified responses
- Cache-Control headers
- Last-Modified headers
- Per-user caching option

**Usage**:
```php
// In routes/web.php
Route::middleware(['httpcache:3600'])->group(function () {
    Route::get('/page', [PageController::class, 'show']);
});

// In controller
public function __construct()
{
    $this->middleware('httpcache:7200')->only(['index', 'show']);
}
```

### 5. API Response Caching

**Middleware**: `app/Http/Middleware/ApiCache.php`

**Features**:
- Version-aware caching
- ETag support
- Resource-based cache tags
- User-specific endpoint detection
- Rate limit headers

**Usage**:
```php
// In routes/api.php
Route::middleware(['apicache:api,60'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
});

// With custom TTL and tag
Route::get('/products', [ProductController::class, 'index'])
    ->middleware('apicache:products,300');
```

### 6. Cache Management Tools

**Commands created**:

1. **Cache Monitor**: `php artisan cache:monitor`
   - Real-time cache statistics
   - Redis & OPcache monitoring
   - Hit rate tracking
   - Export to JSON option

2. **Cache Manager**: `php artisan cache:manage {action}`
   - Actions: clear, warm, flush, status
   - Type-specific operations
   - Tag-based clearing

**Usage examples**:
```bash
# Monitor cache in real-time
php artisan cache:monitor

# Clear specific cache types
php artisan cache:manage clear --type=query --type=api

# Warm all caches
php artisan cache:manage warm

# Check cache status
php artisan cache:manage status

# Clear by tags
php artisan cache:manage clear --tag=products --tag=homepage
```

## Configuration

### Laravel Cache Configuration

**File**: `docker/php/config/laravel-cache.php`

Copy to your Laravel project as `config/cache-optimization.php` and include in your main cache config.

**Features**:
- Multiple Redis connections for different cache types
- TTL presets for different data types
- Cache warming configuration
- Query cache settings
- HTTP cache settings

### Environment Variables

Add to your `.env`:
```env
# Redis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Cache settings
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Cache warming
CACHE_WARMING_ENABLED=true

# Query cache
QUERY_CACHE_ENABLED=true

# HTTP cache
CACHE_HTTP_ENABLED=true
CACHE_HTTP_TTL=3600
```

## Performance Benefits

### Expected Improvements:
- **90%+ cache hit rate** for repeated queries
- **50-70% faster response times** for cached endpoints
- **Reduced database load** by 60-80%
- **Lower server resource usage**
- **Better scalability** under high traffic

### Monitoring Metrics:
- Redis hit/miss ratio
- OPcache hit rate
- Query cache effectiveness
- API response times
- Memory usage trends

## Best Practices

### 1. Cache Invalidation
- Use cache tags for grouped invalidation
- Clear caches on deployments
- Monitor cache hit rates

### 2. TTL Strategy
- Static content: 1 week to 1 year
- API responses: 1-5 minutes
- User data: 30 minutes
- Query results: 5-60 minutes

### 3. Cache Warming
- Run on deployment
- Schedule periodic warming
- Warm critical paths first

### 4. Monitoring
- Check cache stats regularly
- Alert on low hit rates
- Monitor memory usage

## Troubleshooting

### Common Issues:

1. **Low hit rates**
   - Check TTL values
   - Verify cache keys are consistent
   - Look for cache stampedes

2. **Memory issues**
   - Adjust Redis maxmemory
   - Review eviction policy
   - Check for memory leaks

3. **Stale data**
   - Verify invalidation logic
   - Check cache tags
   - Review TTL settings

4. **Performance degradation**
   - Monitor Redis latency
   - Check network connectivity
   - Review query complexity

## Integration with CI/CD

### Deployment Script:
```bash
# Clear old cache
php artisan cache:manage clear --force

# Deploy new code
git pull origin main
composer install --no-dev

# Warm cache
php artisan cache:manage warm

# Verify
php artisan cache:manage status
```

## Next Steps

After Phase 4, proceed to:
- Phase 5: Database connection pooling and optimization

## Additional Resources

- [Laravel Cache Documentation](https://laravel.com/docs/cache)
- [Redis Best Practices](https://redis.io/docs/manual/patterns/)
- [HTTP Caching RFC](https://tools.ietf.org/html/rfc7234)