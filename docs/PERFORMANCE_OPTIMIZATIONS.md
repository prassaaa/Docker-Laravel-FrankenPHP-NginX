# PHP/FrankenPHP Performance Optimizations

This document outlines the performance optimizations implemented in Phase 1 of the optimization process.

## Overview

The optimizations focus on improving PHP and FrankenPHP performance through:
- OPcache configuration with JIT compilation
- FrankenPHP worker optimization
- PHP preloading for Laravel
- Startup optimization scripts
- Performance benchmarking tools

## Implemented Optimizations

### 1. OPcache Configuration

#### Production Settings (`opcache.ini`)
- **Memory**: 256MB allocated for OPcache
- **JIT**: Enabled with 100MB buffer using tracing mode
- **File Validation**: Disabled for maximum performance
- **Preloading**: Configured to load Laravel core files

Key settings:
```ini
opcache.memory_consumption=256
opcache.jit_buffer_size=100M
opcache.jit=1254
opcache.validate_timestamps=0
opcache.preload=/srv/docker/php/config/preload.php
```

#### Development Settings (`opcache-dev.ini`)
- Timestamp validation enabled
- JIT disabled for easier debugging
- Smaller memory allocation (128MB)

### 2. FrankenPHP Worker Configuration

The `Caddyfile` configures:
- Worker mode with automatic worker count (2 × CPU cores)
- File watching for development
- HTTP/3 support
- Compression (Zstandard and Gzip)
- Static asset caching headers

### 3. PHP Preloading

The `preload.php` script loads:
- Laravel Framework core components
- Symfony components used by Laravel
- Common packages (Carbon, Monolog, Guzzle)
- Application bootstrapping files

This reduces the overhead of loading files on each request.

### 4. General PHP Performance Settings

The `performance.ini` file configures:
- Memory limit: 512MB
- Realpath cache: 4MB with 600s TTL
- Redis connection pooling
- Optimized session garbage collection

### 5. Startup Optimization Script

The `startup.sh` script automatically:
- Runs Laravel optimization commands in production
- Caches configuration, routes, and views
- Optimizes Composer autoloader
- Creates necessary directories with correct permissions

## Environment Variables

Key environment variables for tuning:
- `FRANKENPHP_WORKERS`: Number of workers (default: auto)
- `FRANKENPHP_MAX_REQUESTS`: Requests before worker restart
- `APP_ENV`: Controls optimization behavior
- `AUTO_MIGRATE`: Runs migrations on startup if true

## Benchmarking

Use the included `benchmark.sh` script to test performance:

```bash
chmod +x benchmark.sh
./benchmark.sh
```

The script tests:
- Request throughput (requests/second)
- Response times
- OPcache hit rates
- Cold start performance
- Resource usage

## Expected Performance Improvements

With these optimizations, you should see:
- **30-50% faster response times** due to OPcache and JIT
- **Reduced memory usage** from preloading
- **Better throughput** from worker optimization
- **Faster cold starts** from cached configurations

## Monitoring

Monitor performance using:
1. FrankenPHP metrics endpoint: `https://your-domain/metrics`
2. OPcache status via the benchmark script
3. Docker stats for resource usage

## Next Steps

After Phase 1, consider implementing:
- Phase 2: NGINX optimizations
- Phase 3: Docker layer optimizations
- Phase 4: Laravel-specific caching
- Phase 5: Database connection pooling

## Troubleshooting

If you experience issues:

1. **OPcache not working**: Check if the PHP configuration files are properly copied in the Dockerfile
2. **High memory usage**: Reduce `opcache.memory_consumption` or worker count
3. **Preloading errors**: Check the preload.php output in container logs
4. **Slow performance**: Run the benchmark script to identify bottlenecks

## Rollback

To disable optimizations:
1. Remove the custom .ini files from the Dockerfile COPY commands
2. Revert to the original CMD in the Dockerfile
3. Rebuild the container