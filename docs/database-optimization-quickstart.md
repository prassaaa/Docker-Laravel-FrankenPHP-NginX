# Database Optimization Quick Start Guide

This guide will help you quickly implement database optimization in your Laravel project.

## Prerequisites

- Docker and Docker Compose installed
- Laravel project set up
- Basic understanding of database concepts

## Quick Setup (5 minutes)

### 1. Update Environment Variables

Add to your `.env` file:

```bash
# For PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=pgbouncer
DB_PORT=6432
DB_DATABASE=laravel_db
DB_USERNAME=laravel
DB_PASSWORD=your_secure_password

# Direct connection for migrations
DB_HOST_DIRECT=postgres
DB_PORT_DIRECT=5432
DB_MIGRATION_CONNECTION=pgsql_direct

# OR for MySQL
# DB_CONNECTION=mysql
# DB_HOST=proxysql
# DB_PORT=6033
# DB_DATABASE=laravel
# DB_USERNAME=laravel
# DB_PASSWORD=your_secure_password
# DB_HOST_DIRECT=mysql
# DB_PORT_DIRECT=3306
# DB_MIGRATION_CONNECTION=mysql_direct

# Redis for caching
REDIS_HOST=redis
REDIS_PORT=6379

# Monitoring
DB_MONITORING_ENABLED=true
DB_ALERT_SLOW_QUERY_MS=5000
```

### 2. Start Services

```bash
# Development
docker-compose up -d postgres pgbouncer redis

# Or for MySQL
docker-compose up -d mysql proxysql redis

# Production
docker-compose -f docker-compose.prod.yml up -d
```

### 3. Configure Laravel

Replace your `config/database.php` with the optimized version:

```bash
cp config/database-optimized.php config/database.php
```

Or manually add the connection configurations from `database-optimized.php`.

### 4. Register Service Provider

Add to `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\DatabaseServiceProvider::class,
],
```

### 5. Run Migrations

```bash
# Use direct connection for migrations
php artisan migrate --database=pgsql_direct

# Or for MySQL
php artisan migrate --database=mysql_direct
```

## Basic Usage

### Query Optimization

Add to your models:

```php
use App\Traits\OptimizedQueries;

class User extends Model
{
    use OptimizedQueries;
}

// Usage examples:
// Force index usage
User::forceIndex('idx_email')->where('email', $email)->first();

// Efficient counting
$count = User::countEstimate();

// Batch insert
User::insertBatch($largeDataArray, 1000);
```

### Query Caching

```php
use App\Services\QueryCacheService;

$cache = app(QueryCacheService::class);

// Cache a query
$users = $cache->remember(
    User::where('active', true),
    'active-users',
    3600
);

// Invalidate cache
$cache->invalidate(['users']);
```

### Monitoring

```bash
# Check database status
php artisan db:monitor

# Find slow queries
php artisan db:analyze-queries --slow-queries

# Live monitoring
php artisan db:monitor --live
```

## Common Tasks

### 1. Debug Slow Queries

```bash
# Find slow queries
php artisan db:analyze-queries --slow-queries

# Check for missing indexes
php artisan db:analyze-queries --missing-indexes

# View query execution plan
$users = User::where('active', true)->explain();
```

### 2. Monitor Connections

```bash
# View connection pool status
php artisan db:monitor --connections

# Check PgBouncer directly
docker exec -it pgbouncer psql -h localhost -p 6432 -U pgbouncer pgbouncer -c "SHOW POOLS"
```

### 3. Optimize Large Operations

```php
// Process large datasets efficiently
User::chunkWithProgress(1000, function ($users) {
    foreach ($users as $user) {
        // Process user
    }
}, function ($processed) {
    echo "Processed: {$processed} users\n";
});

// Batch updates with JOIN
User::updateWithJoin('profiles', 'users.id = profiles.user_id', [
    'users.updated_at' => now()
]);
```

## Performance Tips

1. **Always use indexes** for columns in WHERE, ORDER BY, and JOIN clauses
2. **Cache frequently accessed data** with appropriate TTLs
3. **Use connection pooling** for all database connections
4. **Monitor slow queries** regularly
5. **Batch large operations** to prevent memory issues

## Troubleshooting

### Connection Refused
```bash
# Check if services are running
docker-compose ps

# Check logs
docker-compose logs pgbouncer
docker-compose logs postgres
```

### Too Many Connections
```bash
# Increase pool size
# Edit docker/pgbouncer/pgbouncer.ini
default_pool_size = 50
max_client_conn = 200

# Restart PgBouncer
docker-compose restart pgbouncer
```

### Slow Queries
```bash
# Add index
php artisan make:migration add_email_index_to_users --table=users

# In migration file:
$table->index('email');
```

## Next Steps

1. Set up automated monitoring (see full documentation)
2. Configure alerts for production
3. Implement query caching for your specific use cases
4. Review and optimize your database schema

For detailed information, see the [full documentation](database-optimization.md).