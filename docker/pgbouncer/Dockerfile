FROM alpine:3.18

# Install PgBouncer and dependencies
RUN apk add --no-cache \
    pgbouncer \
    postgresql-client \
    ca-certificates \
    su-exec

# Create pgbouncer user and directories
RUN adduser -D -S pgbouncer && \
    mkdir -p /var/log/pgbouncer /var/run/pgbouncer && \
    chown -R pgbouncer:pgbouncer /var/log/pgbouncer /var/run/pgbouncer

# Copy configuration
COPY pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
COPY entrypoint.sh /entrypoint.sh

# Make entrypoint executable
RUN chmod +x /entrypoint.sh

# Expose PgBouncer port
EXPOSE 6432

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD pg_isready -h localhost -p 6432 || exit 1

# Run as pgbouncer user
USER pgbouncer

ENTRYPOINT ["/entrypoint.sh"]
CMD ["pgbouncer", "/etc/pgbouncer/pgbouncer.ini"]