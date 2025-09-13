#!/bin/bash

# Laravel FrankenPHP Performance Benchmark Script

echo "====================================="
echo "Laravel FrankenPHP Performance Test"
echo "====================================="

# Configuration
BASE_URL="https://laravel.docker.localhost"
HEALTH_URL="$BASE_URL/health"
API_URL="$BASE_URL/api"
CONCURRENCY=10
REQUESTS=1000
TIMEOUT=30

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to check if service is ready
check_service() {
    echo -n "Checking if service is ready..."
    for i in {1..30}; do
        if curl -k -s -o /dev/null -w "%{http_code}" "$HEALTH_URL" | grep -q "200"; then
            echo -e " ${GREEN}OK${NC}"
            return 0
        fi
        echo -n "."
        sleep 1
    done
    echo -e " ${RED}FAILED${NC}"
    return 1
}

# Function to run Apache Bench test
run_ab_test() {
    local url=$1
    local name=$2
    
    echo -e "\n${YELLOW}Testing: $name${NC}"
    echo "URL: $url"
    echo "Concurrency: $CONCURRENCY"
    echo "Requests: $REQUESTS"
    echo "-----------------------------------"
    
    # Run Apache Bench
    ab -n $REQUESTS -c $CONCURRENCY -k -H "Accept-Encoding: gzip, deflate" "$url" 2>&1 | grep -E "Requests per second:|Time per request:|Transfer rate:|Failed requests:|Non-2xx responses:"
}

# Function to check OPcache status
check_opcache() {
    echo -e "\n${YELLOW}OPcache Status:${NC}"
    docker compose -f docker-compose.development.yml exec php php -r '
        $status = opcache_get_status();
        echo "Enabled: " . ($status["opcache_enabled"] ? "Yes" : "No") . "\n";
        echo "Cache hits: " . $status["opcache_statistics"]["hits"] . "\n";
        echo "Cache misses: " . $status["opcache_statistics"]["misses"] . "\n";
        echo "Hit rate: " . round($status["opcache_statistics"]["opcache_hit_rate"], 2) . "%\n";
        echo "Memory used: " . round($status["memory_usage"]["used_memory"] / 1024 / 1024, 2) . " MB\n";
        echo "Memory free: " . round($status["memory_usage"]["free_memory"] / 1024 / 1024, 2) . " MB\n";
        echo "Cached scripts: " . $status["opcache_statistics"]["num_cached_scripts"] . "\n";
        echo "JIT enabled: " . (ini_get("opcache.jit") ? "Yes" : "No") . "\n";
    ' 2>/dev/null || echo "Unable to get OPcache status"
}

# Function to check FrankenPHP metrics
check_frankenphp_metrics() {
    echo -e "\n${YELLOW}FrankenPHP Metrics:${NC}"
    curl -k -s "$BASE_URL/metrics" 2>/dev/null | grep -E "frankenphp_|caddy_http_" | head -20 || echo "Metrics endpoint not available"
}

# Function to measure cold start time
measure_cold_start() {
    echo -e "\n${YELLOW}Measuring Cold Start Time:${NC}"
    
    # Restart the container to ensure cold start
    docker compose -f docker-compose.development.yml restart php > /dev/null 2>&1
    sleep 5
    
    # Measure time to first byte
    local start_time=$(date +%s%N)
    curl -k -s -o /dev/null -w "Time to first byte: %{time_starttransfer}s\n" "$BASE_URL"
    local end_time=$(date +%s%N)
    
    local duration=$((($end_time - $start_time) / 1000000))
    echo "Total cold start time: ${duration}ms"
}

# Main execution
echo "Starting performance benchmark..."

# Check if Docker containers are running
if ! docker compose -f docker-compose.development.yml ps | grep -q "Up"; then
    echo -e "${RED}Error: Docker containers are not running.${NC}"
    echo "Please run: docker-compose -f docker-compose.development.yml up -d"
    exit 1
fi

# Check if service is ready
if ! check_service; then
    echo -e "${RED}Error: Service is not responding.${NC}"
    exit 1
fi

# Warm up the cache
echo -e "\n${YELLOW}Warming up cache...${NC}"
curl -k -s "$BASE_URL" > /dev/null
sleep 2

# Run benchmarks
echo -e "\n${GREEN}Running Performance Tests${NC}"

# Test 1: Homepage
run_ab_test "$BASE_URL/" "Homepage"

# Test 2: API endpoint (if exists)
run_ab_test "$API_URL/user" "API Endpoint"

# Test 3: Static asset
run_ab_test "$BASE_URL/favicon.ico" "Static Asset"

# Check OPcache status
check_opcache

# Check FrankenPHP metrics
check_frankenphp_metrics

# Measure cold start
measure_cold_start

# Memory usage
echo -e "\n${YELLOW}Container Resource Usage:${NC}"
docker stats --no-stream --format "table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}" | grep php

echo -e "\n${GREEN}Benchmark completed!${NC}"
echo "====================================="

# Save results to file
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RESULTS_FILE="benchmark_results_$TIMESTAMP.txt"

{
    echo "Benchmark Results - $TIMESTAMP"
    echo "====================================="
    echo ""
    echo "Configuration:"
    echo "- Concurrency: $CONCURRENCY"
    echo "- Total Requests: $REQUESTS"
    echo "- URL: $BASE_URL"
    echo ""
    echo "Results saved to: $RESULTS_FILE"
} > "$RESULTS_FILE"

echo -e "\nResults saved to: ${GREEN}$RESULTS_FILE${NC}"