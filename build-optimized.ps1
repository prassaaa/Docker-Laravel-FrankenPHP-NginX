# Optimized Docker build script for Windows PowerShell

param(
    [string]$BuildEnv = "development",
    [bool]$ParallelBuilds = $true,
    [bool]$NoCache = $false
)

# Colors for output
$Green = [ConsoleColor]::Green
$Yellow = [ConsoleColor]::Yellow
$Red = [ConsoleColor]::Red

Write-Host "=== Optimized Docker Build Script ===" -ForegroundColor $Green

# Enable BuildKit
$env:DOCKER_BUILDKIT = "1"
$env:COMPOSE_DOCKER_CLI_BUILD = "1"
$env:BUILDKIT_PROGRESS = "plain"

Write-Host "Build Environment: $BuildEnv" -ForegroundColor $Yellow
Write-Host "Parallel Builds: $ParallelBuilds" -ForegroundColor $Yellow
Write-Host "No Cache: $NoCache" -ForegroundColor $Yellow

# Function to build with retries
function Build-WithRetry {
    param(
        [string]$Service,
        [int]$MaxAttempts = 3
    )
    
    for ($attempt = 1; $attempt -le $MaxAttempts; $attempt++) {
        Write-Host "Building $Service (attempt $attempt)..." -ForegroundColor $Yellow
        
        $buildArgs = @("-f", "docker-compose.$BuildEnv.yml", "build")
        if ($NoCache) {
            $buildArgs += "--no-cache"
        }
        $buildArgs += $Service
        
        $result = Start-Process -FilePath "docker-compose" -ArgumentList $buildArgs -NoNewWindow -PassThru -Wait
        
        if ($result.ExitCode -eq 0) {
            Write-Host "✓ $Service built successfully" -ForegroundColor $Green
            return $true
        }
        
        Write-Host "Build failed for $Service, retrying..." -ForegroundColor $Red
        Start-Sleep -Seconds 2
    }
    
    Write-Host "✗ Failed to build $Service after $MaxAttempts attempts" -ForegroundColor $Red
    return $false
}

# Clean up old images
function Cleanup-OldImages {
    Write-Host "Cleaning up old images..." -ForegroundColor $Yellow
    docker image prune -f --filter "until=24h"
}

# Build services
if ($ParallelBuilds) {
    Write-Host "Building services in parallel..." -ForegroundColor $Yellow
    
    # Create background jobs
    $phpJob = Start-Job -ScriptBlock {
        param($BuildEnv, $NoCache)
        & docker-compose -f "docker-compose.$BuildEnv.yml" build $(if($NoCache){"--no-cache"}) php
    } -ArgumentList $BuildEnv, $NoCache
    
    $nginxJob = Start-Job -ScriptBlock {
        param($BuildEnv, $NoCache)
        & docker-compose -f "docker-compose.$BuildEnv.yml" build $(if($NoCache){"--no-cache"}) nginx
    } -ArgumentList $BuildEnv, $NoCache
    
    # Wait for jobs to complete
    $phpResult = Wait-Job $phpJob | Receive-Job
    $nginxResult = Wait-Job $nginxJob | Receive-Job
    
    Remove-Job $phpJob, $nginxJob
    
    if ($LASTEXITCODE -ne 0) {
        Write-Host "One or more builds failed" -ForegroundColor $Red
        exit 1
    }
} else {
    Write-Host "Building services sequentially..." -ForegroundColor $Yellow
    
    if (-not (Build-WithRetry -Service "php")) { exit 1 }
    if (-not (Build-WithRetry -Service "nginx")) { exit 1 }
}

# Build dependent services
if ($BuildEnv -eq "development") {
    if (-not (Build-WithRetry -Service "node")) { exit 1 }
}

# Verify builds
Write-Host "Verifying builds..." -ForegroundColor $Yellow
docker-compose -f "docker-compose.$BuildEnv.yml" config --quiet

if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ All builds completed successfully" -ForegroundColor $Green
} else {
    Write-Host "✗ Build verification failed" -ForegroundColor $Red
    exit 1
}

# Show image sizes
Write-Host "Image sizes:" -ForegroundColor $Yellow
docker images --format "table {{.Repository}}:{{.Tag}}`t{{.Size}}" | Select-String -Pattern "(laravel|php|nginx)"

# Clean up
Cleanup-OldImages

Write-Host "Build process completed!" -ForegroundColor $Green

# Optionally start services
$response = Read-Host "Do you want to start the services now? (y/n)"
if ($response -eq 'y' -or $response -eq 'Y') {
    docker-compose -f "docker-compose.$BuildEnv.yml" up -d
    Write-Host "Services started!" -ForegroundColor $Green
}