<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureConnections();
        $this->configurePdoOptions();
        $this->registerQueryLogging();
    }

    /**
     * Configure database connections.
     *
     * @return void
     */
    protected function configureConnections()
    {
        // Configure reconnect handler
        Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            $connection->reconnector(function ($connection) {
                Log::info('MySQL connection reconnected');
            });
            
            return $connection;
        });

        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            $connection->reconnector(function ($connection) {
                Log::info('PostgreSQL connection reconnected');
            });
            
            return $connection;
        });
    }

    /**
     * Configure PDO options for all connections.
     *
     * @return void
     */
    protected function configurePdoOptions()
    {
        // Set statement timeout for PostgreSQL
        if (config('database.default') === 'pgsql') {
            DB::statement("SET statement_timeout = '30s'");
            DB::statement("SET idle_in_transaction_session_timeout = '60s'");
            DB::statement("SET lock_timeout = '10s'");
        }

        // Set session variables for MySQL
        if (config('database.default') === 'mysql') {
            DB::statement("SET SESSION wait_timeout = 28800");
            DB::statement("SET SESSION interactive_timeout = 28800");
            DB::statement("SET SESSION max_execution_time = 30000"); // 30 seconds
        }
    }

    /**
     * Register query logging for slow queries.
     *
     * @return void
     */
    protected function registerQueryLogging()
    {
        if (config('database.log_slow_queries', false)) {
            DB::listen(function (QueryExecuted $query) {
                $slowQueryTime = config('database.slow_query_time', 2) * 1000; // Convert to milliseconds
                
                if ($query->time > $slowQueryTime) {
                    Log::warning('Slow query detected', [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'time' => $query->time . 'ms',
                        'connection' => $query->connectionName,
                    ]);
                }
            });
        }

        if (config('database.log_queries', false)) {
            DB::listen(function (QueryExecuted $query) {
                Log::debug('Query executed', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time' => $query->time . 'ms',
                    'connection' => $query->connectionName,
                ]);
            });
        }
    }
}