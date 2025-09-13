# NGINX Performance Optimizations (Phase 2)

This document outlines the NGINX performance optimizations implemented in Phase 2.

## Overview

The optimizations focus on:
- Buffer sizes and timeout optimization
- Brotli compression alongside Gzip
- Advanced static asset caching
- Rate limiting and DDoS protection
- SSL/TLS optimization
- Performance monitoring with Lua

## Implemented Optimizations

### 1. Buffer Sizes and Timeouts

Optimized buffer configurations in `nginx.conf`:
- **Client buffers**: 128KB body buffer, 50MB max body size
- **Proxy buffers**: 8x256KB buffers for FrankenPHP communication
- **Timeouts**: Balanced between performance and reliability (30s-60s)
- **DirectIO**: Enabled for files >10MB
- **AIO**: Threaded async I/O for better performance

### 2. Brotli Compression

Implemented via custom OpenResty build:
- **Compression level**: 6 (balanced)
- **Min length**: 1000 bytes
- **Static compression**: Enabled for pre-compressed files
- **Better ratios**: 20-30% smaller than Gzip for text content

### 3. Static Asset Caching

Advanced caching strategy (`static-cache.conf`):
- **Immutable assets**: CSS/JS with 1-year cache
- **Images/Fonts**: 1-year cache with immutable flag
- **HTML**: 5-minute cache with must-revalidate
- **Versioned assets**: Detected and cached permanently
- **ETags**: Enabled for cache validation
- **WebP support**: Auto-detection for modern image formats

### 4. Rate Limiting

Multi-layer protection (`rate-limiting.conf`):
- **API endpoints**: 10 req/s with burst of 20
- **Auth endpoints**: 5 req/min with burst of 10
- **Static assets**: 100 req/s with burst of 200
- **Search/Heavy**: 2 req/s with burst of 5
- **Connection limits**: Per-IP connection throttling
- **Whitelisting**: Internal IPs bypass limits

### 5. SSL/TLS Optimization

Modern SSL configuration (`ssl-optimization.conf`):
- **Protocols**: TLS 1.2 and 1.3 only
- **Ciphers**: Modern, secure cipher suites
- **OCSP Stapling**: Enabled for faster certificate validation
- **Session cache**: 50MB shared cache
- **0-RTT**: Enabled for TLS 1.3 (except APIs)
- **HTTP/2 Push**: For critical resources
- **HSTS**: Strict transport security with preload

### 6. Performance Monitoring

Comprehensive monitoring (`monitoring.conf`):
- **Basic metrics**: `/nginx_status` endpoint
- **Extended metrics**: JSON format with Lua
- **Health checks**: Simple and detailed versions
- **Performance logs**: Custom format with timing data
- **Prometheus format**: `/metrics` endpoint
- **Request tracing**: Debug endpoint with headers

## Configuration Files

### New Files Created:
- `docker/nginx/nginx.dockerfile` - Custom OpenResty build
- `docker/nginx/conf.d/static-cache.conf` - Caching rules
- `docker/nginx/conf.d/rate-limiting.conf` - Rate limit zones
- `docker/nginx/conf.d/ssl-optimization.conf` - SSL settings
- `docker/nginx/conf.d/monitoring.conf` - Monitoring endpoints
- `docker/nginx/ssl/generate-dhparam.sh` - DH param generator

### Modified Files:
- `docker/nginx/nginx.conf` - Core optimizations
- `docker/nginx/conf.d/app.conf` - Include new configs
- `docker-compose.*.yml` - Use custom NGINX build

## Performance Impact

Expected improvements:
- **30-40% better compression** with Brotli
- **50% faster SSL handshakes** with session resumption
- **Reduced latency** from optimized buffers
- **Better protection** against DDoS attacks
- **Improved caching** for static assets

## Monitoring

Access performance metrics:
1. Basic status: `https://your-domain/nginx_status`
2. Extended status: `https://your-domain/nginx_status_extended`
3. Health check: `https://your-domain/health`
4. Prometheus metrics: `https://your-domain/metrics`

View logs:
- Performance log: `/var/log/nginx/performance.log`
- Analytics log: `/var/log/nginx/analytics.log`

## Security Considerations

1. **Rate limiting** protects against abuse
2. **SSL optimization** maintains security while improving performance
3. **Monitoring endpoints** restricted to internal IPs
4. **Security headers** prevent common attacks

## Tuning Guide

### Buffer Sizes
Adjust based on your needs:
```nginx
client_body_buffer_size 128k;  # Increase for large POST requests
proxy_buffer_size 128k;         # Increase for large headers
```

### Rate Limits
Modify zones in `rate-limiting.conf`:
```nginx
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
```

### Compression
Balance CPU vs bandwidth:
```nginx
brotli_comp_level 6;  # 1-11, higher = better compression
gzip_comp_level 6;    # 1-9, higher = more CPU usage
```

## Troubleshooting

1. **429 Too Many Requests**: Check rate limit configuration
2. **SSL errors**: Ensure DH parameters are generated
3. **Monitoring not working**: Verify OpenResty installation
4. **High memory usage**: Reduce buffer sizes or cache zones

## Next Steps

After Phase 2, continue with:
- Phase 3: Docker layer optimizations
- Phase 4: Laravel-specific caching
- Phase 5: Database connection pooling