<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\DatabaseMonitoringService;
use Illuminate\Support\Facades\Log;

class CollectDatabaseMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(DatabaseMonitoringService $monitor)
    {
        try {
            // Collect connection pool metrics
            $connectionMetrics = $monitor->getConnectionPoolMetrics();
            $monitor->storeMetrics('connections', $connectionMetrics);
            
            // Check for connection pool alerts
            $monitor->checkAlerts($connectionMetrics);
            
            // Collect query performance metrics
            $queryMetrics = $monitor->getQueryPerformanceMetrics();
            $monitor->storeMetrics('queries', $queryMetrics);
            
            // Check for query performance alerts
            $monitor->checkAlerts($queryMetrics);
            
            Log::info('Database metrics collected successfully', [
                'timestamp' => now()->toIso8601String(),
                'connection_pools' => array_keys($connectionMetrics['pools'] ?? []),
                'query_stats_count' => count($queryMetrics['query_stats'] ?? [])
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to collect database metrics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e; // Re-throw to mark job as failed
        }
    }

    /**
     * The job failed to process.
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        Log::error('Database metrics collection job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}