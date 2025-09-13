#!/bin/bash

# Optimized Docker build script with BuildKit and caching strategies

set -e

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== Optimized Docker Build Script ===${NC}"

# Enable BuildKit
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1
export BUILDKIT_PROGRESS=plain

# Build arguments
BUILD_ENV="${1:-development}"
PARALLEL_BUILDS="${2:-true}"
NO_CACHE="${3:-false}"

echo -e "${YELLOW}Build Environment: $BUILD_ENV${NC}"
echo -e "${YELLOW}Parallel Builds: $PARALLEL_BUILDS${NC}"
echo -e "${YELLOW}No Cache: $NO_CACHE${NC}"

# Function to build with retries
build_with_retry() {
    local service=$1
    local max_attempts=3
    local attempt=1
    
    while [ $attempt -le $max_attempts ]; do
        echo -e "${YELLOW}Building $service (attempt $attempt)...${NC}"
        
        if [ "$NO_CACHE" = "true" ]; then
            if docker-compose -f docker-compose.$BUILD_ENV.yml build --no-cache $service; then
                echo -e "${GREEN}✓ $service built successfully${NC}"
                return 0
            fi
        else
            if docker-compose -f docker-compose.$BUILD_ENV.yml build $service; then
                echo -e "${GREEN}✓ $service built successfully${NC}"
                return 0
            fi
        fi
        
        echo -e "${RED}Build failed for $service, retrying...${NC}"
        attempt=$((attempt + 1))
        sleep 2
    done
    
    echo -e "${RED}✗ Failed to build $service after $max_attempts attempts${NC}"
    return 1
}

# Clean up old images
cleanup_old_images() {
    echo -e "${YELLOW}Cleaning up old images...${NC}"
    docker image prune -f --filter "until=24h"
}

# Build services
if [ "$PARALLEL_BUILDS" = "true" ]; then
    echo -e "${YELLOW}Building services in parallel...${NC}"
    
    # Build base images first
    build_with_retry php &
    PHP_PID=$!
    
    build_with_retry nginx &
    NGINX_PID=$!
    
    # Wait for base builds to complete
    wait $PHP_PID
    PHP_RESULT=$?
    
    wait $NGINX_PID
    NGINX_RESULT=$?
    
    if [ $PHP_RESULT -ne 0 ] || [ $NGINX_RESULT -ne 0 ]; then
        echo -e "${RED}One or more builds failed${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}Building services sequentially...${NC}"
    build_with_retry php || exit 1
    build_with_retry nginx || exit 1
fi

# Build dependent services
if [ "$BUILD_ENV" = "development" ]; then
    build_with_retry node || exit 1
fi

# Verify builds
echo -e "${YELLOW}Verifying builds...${NC}"
docker-compose -f docker-compose.$BUILD_ENV.yml config --quiet

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ All builds completed successfully${NC}"
else
    echo -e "${RED}✗ Build verification failed${NC}"
    exit 1
fi

# Show image sizes
echo -e "${YELLOW}Image sizes:${NC}"
docker images --format "table {{.Repository}}:{{.Tag}}\t{{.Size}}" | grep -E "(laravel|php|nginx)"

# Clean up
cleanup_old_images

echo -e "${GREEN}Build process completed!${NC}"

# Optionally start services
read -p "Do you want to start the services now? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    docker-compose -f docker-compose.$BUILD_ENV.yml up -d
    echo -e "${GREEN}Services started!${NC}"
fi