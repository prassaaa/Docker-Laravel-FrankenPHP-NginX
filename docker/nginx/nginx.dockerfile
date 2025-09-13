# Use OpenResty for Lua support
FROM openresty/openresty:1.21.4.3-alpine AS builder

# Install build dependencies
RUN apk add --no-cache \
    git \
    gcc \
    make \
    libc-dev \
    pcre-dev \
    zlib-dev \
    openssl-dev \
    linux-headers

# Clone Brotli module
WORKDIR /tmp
RUN git clone --recurse-submodules https://github.com/google/ngx_brotli.git

# Build Brotli module for OpenResty
WORKDIR /tmp/ngx_brotli
RUN make modules \
    NGINX_SRC_DIR=/usr/local/openresty/nginx \
    NGX_BROTLI_STATIC_MODULE_ONLY=1

# Final stage
FROM openresty/openresty:1.21.4.3-alpine

# Copy Brotli modules from builder
COPY --from=builder /tmp/ngx_brotli/*.so /usr/local/openresty/nginx/modules/

# Install runtime dependencies
RUN apk add --no-cache \
    brotli \
    lua5.1-cjson \
    lua5.1-socket

# Create directories
RUN mkdir -p /usr/local/openresty/nginx/conf/conf.d \
    /usr/local/openresty/nginx/logs \
    /var/log/nginx

# Create symlinks for compatibility
RUN ln -sf /usr/local/openresty/nginx/conf /etc/nginx && \
    ln -sf /usr/local/openresty/nginx /usr/lib/nginx && \
    ln -sf /usr/local/openresty/bin/openresty /usr/local/bin/nginx

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD nginx -t && wget -q --spider http://localhost/nginx_status || exit 1